<?php

namespace App\Domain\Risk\Services;

use App\Domain\Instruments\Support\TickSize;
use App\Domain\MarketData\Services\FxConversionService;
use App\Models\Instrument;
use App\Models\Portfolio;
use App\Models\PortfolioRebalanceTarget;
use App\Models\User;
use App\Support\Math\Dec;
use Brick\Math\RoundingMode;

/**
 * Two-Pass Lot-Aware, Fee-Aware, Safety-Constrained Portfolio Rebalancing Simulator.
 *
 * Pass 1 identifies drift outside configured band and executes overweight SELLs (capped
 * by actual held shares and exchange board-lot multiples). Pass 2 allocates resulting
 * spendable cash (above configured target cash buffer plus optional fresh capital inflow)
 * across underweight BUYs while enforcing Stock Safety safeguard suppression rules.
 */
final class PortfolioRebalancingService
{
    public function __construct(
        private readonly FxConversionService $fx,
    ) {}

    /**
     * @param  array<string, mixed>  $valuationSummary
     * @param  array<string, mixed>  $prefsOverride
     * @param  list<array{instrument_id: int, target_weight_pct: float|string}>|null  $targetsOverride
     * @return array<string, mixed>
     */
    public function simulate(
        User $user,
        Portfolio $portfolio,
        array $valuationSummary,
        array $prefsOverride = [],
        ?array $targetsOverride = null,
        string $additionalCashInflowRep = '0',
    ): array {
        $summary = $valuationSummary['summary'] ?? [];
        $holdings = $valuationSummary['consolidatedHoldings'] ?? [];
        $targetCcy = strtoupper((string) ($summary['reporting_currency'] ?? 'IDR'));

        $prefs = $portfolio->mergedRiskRebalancePrefs();
        if (isset($prefsOverride['cash_buffer_pct']) && is_numeric($prefsOverride['cash_buffer_pct'])) {
            $prefs['cash_buffer_pct'] = max(0.0, min(95.0, (float) $prefsOverride['cash_buffer_pct']));
        }
        if (isset($prefsOverride['drift_band_pct']) && is_numeric($prefsOverride['drift_band_pct'])) {
            $prefs['drift_band_pct'] = max(0.0, min(25.0, (float) $prefsOverride['drift_band_pct']));
        }
        if (array_key_exists('exclude_safety_flagged_buys', $prefsOverride)) {
            $prefs['exclude_safety_flagged_buys'] = (bool) $prefsOverride['exclude_safety_flagged_buys'];
        }

        $cashBufferPct = (float) $prefs['cash_buffer_pct'];
        $driftBandPct = (float) $prefs['drift_band_pct'];
        $excludeSafetyBuys = (bool) $prefs['exclude_safety_flagged_buys'];
        $inflowRep = Dec::max('0.0000', Dec::round($additionalCashInflowRep ?: '0', 4));

        // Load saved target allocations (or override / non-persisted proportional preview)
        $targetWeightByInst = [];
        $isDefaultPreview = false;

        if ($targetsOverride !== null) {
            foreach ($targetsOverride as $t) {
                $iid = (int) $t['instrument_id'];
                $w = max(0.0, min(100.0, (float) $t['target_weight_pct']));
                if ($w > 0.0) {
                    $targetWeightByInst[$iid] = round($w, 2);
                }
            }
        } else {
            $savedTargets = PortfolioRebalanceTarget::query()
                ->where('user_id', $user->id)
                ->where('portfolio_id', $portfolio->id)
                ->get();

            if ($savedTargets->isNotEmpty()) {
                foreach ($savedTargets as $st) {
                    $w = max(0.0, min(100.0, (float) $st->target_weight_pct));
                    if ($w > 0.0) {
                        $targetWeightByInst[(int) $st->instrument_id] = round($w, 2);
                    }
                }
            } elseif (! empty($holdings)) {
                $isDefaultPreview = true;
                $targetWeightByInst = $this->buildCurrentProportionalTargets($holdings, $cashBufferPct);
            }
        }

        $holdingsByInst = [];
        foreach ($holdings as $h) {
            $holdingsByInst[(int) $h['instrument_id']] = $h;
        }

        $allInstIds = array_values(array_unique(array_merge(
            array_keys($holdingsByInst),
            array_keys($targetWeightByInst),
        )));

        if (empty($allInstIds)) {
            $curCash = (string) ($summary['cash_balance'] ?? '0.0000');
            $curEq = (string) ($summary['total_equity'] ?? '0.0000');

            return [
                'has_data' => false,
                'is_default_preview' => $isDefaultPreview,
                'reporting_currency' => $targetCcy,
                'prefs' => $prefs,
                'additional_cash_inflow_rep' => $inflowRep,
                'allocated_target_pct' => '0.00',
                'cash_buffer_pct' => number_format($cashBufferPct, 2, '.', ''),
                'total_target_plus_cash_pct' => number_format($cashBufferPct, 2, '.', ''),
                'over_allocated' => false,
                'buys_scaled_by_cash' => false,
                'safety_blocked_count' => 0,
                'pre_total_equity_rep' => $curEq,
                'post_inflow_equity_rep' => Dec::add($curEq, $inflowRep, 4),
                'pre_cash_rep' => $curCash,
                'target_cash_rep' => Dec::mul(Dec::add($curEq, $inflowRep, 4), number_format($cashBufferPct / 100.0, 6, '.', ''), 4),
                'post_cash_rep' => Dec::add($curCash, $inflowRep, 4),
                'post_total_equity_rep' => Dec::add($curEq, $inflowRep, 4),
                'total_buy_gross_rep' => '0.0000',
                'total_sell_gross_rep' => '0.0000',
                'total_estimated_fees_rep' => '0.0000',
                'turnover_pct' => '0.00000000',
                'pre_hhi' => null,
                'post_hhi' => null,
                'rows' => [],
            ];
        }

        $instruments = Instrument::query()
            ->with([
                'exchange:id,code',
                'latestQuote:instrument_id,close',
                'safetyAssessment:instrument_id,risk_category,risk_score,safeguard_triggered',
            ])
            ->whereIn('id', $allInstIds)
            ->get()
            ->keyBy('id');

        $currentCashRep = (string) ($summary['cash_balance'] ?? '0.0000');
        $currentEquityRep = (string) ($summary['total_equity'] ?? '0.0000');
        $postInflowEquityRep = Dec::add($currentEquityRep, $inflowRep, 4);
        $targetCashRep = Dec::mul(
            $postInflowEquityRep,
            number_format($cashBufferPct / 100.0, 6, '.', ''),
            4,
        );

        $sumTargetWeightPct = array_sum($targetWeightByInst);
        $overAllocated = ($sumTargetWeightPct + $cashBufferPct) > 100.01;

        $rows = [];
        $buyQueue = [];
        $totalSellNetProceedsRep = '0.0000';
        $totalSellGrossRep = '0.0000';
        $totalBuyGrossRep = '0.0000';
        $totalFeesRep = '0.0000';
        $totalBuyDeficitRep = '0.0000';
        $safetyBlockedCount = 0;

        $tradingCosts = config('nadi.trading_costs', []);

        foreach ($allInstIds as $instId) {
            $inst = $instruments->get($instId);
            if (! $inst) {
                continue;
            }
            $h = $holdingsByInst[$instId] ?? null;
            $exCode = strtoupper($inst->exchange?->code ?? 'DEFAULT');
            $instCcy = strtoupper($inst->currency);
            $lotSize = max(1, (int) $inst->lot_size);
            $costSchedule = $tradingCosts[$exCode] ?? $tradingCosts['DEFAULT'] ?? [
                'buy_fee_pct' => '0.0015',
                'sell_fee_pct' => '0.0015',
                'slippage_pct' => '0.0010',
            ];
            $buyFeePct = (string) $costSchedule['buy_fee_pct'];
            $sellFeePct = (string) $costSchedule['sell_fee_pct'];
            $slippagePct = (string) $costSchedule['slippage_pct'];

            $heldShares = $h ? (string) $h['quantity'] : '0.000000';
            $avgCostLocal = $h ? (string) $h['avg_cost_per_share'] : '0.000000';
            $rawClose = $h['close'] ?? ($inst->latestQuote ? (string) $inst->latestQuote->close : null);
            $refPrice = ($rawClose !== null && Dec::cmp((string) $rawClose, '0') > 0)
                ? TickSize::roundToTick($exCode, (string) $rawClose, 6)
                : null;

            $fxRate = $this->fx->rate($instCcy, $targetCcy);

            $currentMvRep = $h && isset($h['market_value_reporting'])
                ? (string) $h['market_value_reporting']
                : '0.0000';

            $currentWeightPct = Dec::cmp($postInflowEquityRep, '0') > 0
                ? (float) (Dec::div(Dec::mul($currentMvRep, '100', 8), $postInflowEquityRep, 4) ?? '0')
                : 0.0;

            $targetWeightPct = $targetWeightByInst[$instId] ?? 0.0;
            $targetMvRep = Dec::mul(
                $postInflowEquityRep,
                number_format($targetWeightPct / 100.0, 6, '.', ''),
                4,
            );
            $driftPct = $currentWeightPct - $targetWeightPct;

            $safetyTriggered = (bool) ($inst->safetyAssessment?->safeguard_triggered ?? ($h['safeguard_triggered'] ?? false));
            $safetyCategory = (string) ($inst->safetyAssessment?->risk_category ?? ($h['safety_category'] ?? 'insufficient_data'));

            $row = [
                'instrument_id' => $instId,
                'symbol' => $inst->symbol,
                'name' => $inst->name,
                'exchange_code' => $exCode,
                'currency' => $instCcy,
                'lot_size' => $lotSize,
                'fx_to_reporting' => $fxRate,
                'ref_price_local' => $refPrice,
                'held_shares' => Dec::round($heldShares, 6),
                'current_mv_rep' => $currentMvRep,
                'current_weight_pct' => number_format($currentWeightPct, 2, '.', ''),
                'target_weight_pct' => number_format($targetWeightPct, 2, '.', ''),
                'target_mv_rep' => $targetMvRep,
                'drift_pct' => number_format($driftPct, 2, '.', ''),
                'safety_category' => $safetyCategory,
                'safeguard_triggered' => $safetyTriggered,
                'action' => 'hold_within_band',
                'suggested_lots' => 0,
                'suggested_shares' => '0.000000',
                'estimated_gross_local' => '0.0000',
                'estimated_gross_rep' => '0.0000',
                'estimated_fee_rep' => '0.0000',
                'net_cash_impact_rep' => '0.0000',
                'indicative_realized_pnl_rep' => null,
                'post_shares' => Dec::round($heldShares, 6),
                'post_mv_rep' => $currentMvRep,
                'post_weight_pct' => '0.00',
                'post_drift_pct' => '0.00',
                '_buy_fee_pct' => $buyFeePct,
                '_slippage_pct' => $slippagePct,
                '_avg_cost_local' => $avgCostLocal,
            ];

            if ($refPrice === null || Dec::cmp($refPrice, '0') <= 0) {
                $row['action'] = 'missing_price';
                $rows[$instId] = $row;
                continue;
            }

            if ($fxRate === null || Dec::cmp($fxRate, '0') <= 0) {
                $row['action'] = 'missing_fx';
                $rows[$instId] = $row;
                continue;
            }

            if (abs($driftPct) <= $driftBandPct) {
                $row['action'] = 'hold_within_band';
                $rows[$instId] = $row;
                continue;
            }

            if ($driftPct > $driftBandPct) {
                $excessRep = Dec::sub($currentMvRep, $targetMvRep, 4);
                $excessLoc = Dec::div($excessRep, $fxRate, 6) ?? '0';
                $rawSellShares = Dec::div($excessLoc, $refPrice, 6) ?? '0';

                $maxSellLots = (int) (string) Dec::of($heldShares)->dividedBy((string) $lotSize, 0, RoundingMode::DOWN);
                $idealSellLots = (int) (string) Dec::of($rawSellShares)->dividedBy((string) $lotSize, 0, RoundingMode::DOWN);
                $sellLots = max(0, min($maxSellLots, $idealSellLots));

                if ($sellLots > 0) {
                    $sellShares = (string) ($sellLots * $lotSize);
                    $grossLoc = Dec::mul($sellShares, $refPrice, 4);
                    $feeLoc = Dec::mul($grossLoc, $sellFeePct, 4);
                    $netProceedsLoc = Dec::sub($grossLoc, $feeLoc, 4);

                    $grossRep = Dec::mul($grossLoc, $fxRate, 4);
                    $feeRep = Dec::mul($feeLoc, $fxRate, 4);
                    $netProceedsRep = Dec::mul($netProceedsLoc, $fxRate, 4);
                    $costRelievedLoc = Dec::mul($sellShares, $avgCostLocal, 4);
                    $realizedPnlRep = Dec::mul(Dec::sub($netProceedsLoc, $costRelievedLoc, 4), $fxRate, 4);

                    $postShares = Dec::max('0.000000', Dec::sub($heldShares, $sellShares, 6));
                    $postMvRep = Dec::mul(Dec::mul($postShares, $refPrice, 4), $fxRate, 4);

                    $row['action'] = 'sell';
                    $row['suggested_lots'] = $sellLots;
                    $row['suggested_shares'] = Dec::round($sellShares, 6);
                    $row['estimated_gross_local'] = $grossLoc;
                    $row['estimated_gross_rep'] = $grossRep;
                    $row['estimated_fee_rep'] = $feeRep;
                    $row['net_cash_impact_rep'] = $netProceedsRep;
                    $row['indicative_realized_pnl_rep'] = $realizedPnlRep;
                    $row['post_shares'] = $postShares;
                    $row['post_mv_rep'] = $postMvRep;

                    $totalSellNetProceedsRep = Dec::add($totalSellNetProceedsRep, $netProceedsRep, 4);
                    $totalSellGrossRep = Dec::add($totalSellGrossRep, $grossRep, 4);
                    $totalFeesRep = Dec::add($totalFeesRep, $feeRep, 4);
                } else {
                    $row['action'] = 'hold_lot_rounding';
                }

                $rows[$instId] = $row;
                continue;
            }

            // Underweight => BUY candidate
            if ($excludeSafetyBuys && $safetyTriggered) {
                $row['action'] = 'blocked_by_safety';
                $safetyBlockedCount++;
                $rows[$instId] = $row;
                continue;
            }

            $deficitRep = Dec::max('0.0000', Dec::sub($targetMvRep, $currentMvRep, 4));
            if (Dec::cmp($deficitRep, '0') > 0) {
                $totalBuyDeficitRep = Dec::add($totalBuyDeficitRep, $deficitRep, 4);
                $buyQueue[$instId] = $deficitRep;
            }

            $rows[$instId] = $row;
        }

        // Pass 2: Execute cash-constrained BUY orders
        $spendableBase = Dec::add(Dec::add($currentCashRep, $inflowRep, 4), $totalSellNetProceedsRep, 4);
        $availableForBuysRep = Dec::max('0.0000', Dec::sub($spendableBase, $targetCashRep, 4));

        $buysScaledByCash = false;
        $buyScale = '1.00000000';
        if (Dec::cmp($totalBuyDeficitRep, '0') > 0 && Dec::cmp($availableForBuysRep, $totalBuyDeficitRep) < 0) {
            $buysScaledByCash = true;
            $buyScale = Dec::div($availableForBuysRep, $totalBuyDeficitRep, 8) ?? '0.00000000';
        }

        $remainingCashRep = $availableForBuysRep;

        foreach ($buyQueue as $instId => $deficitRep) {
            $row = &$rows[$instId];
            $allocRep = Dec::mul($deficitRep, $buyScale, 4);
            if (Dec::cmp($allocRep, $remainingCashRep) > 0) {
                $allocRep = $remainingCashRep;
            }

            $fxRate = (string) $row['fx_to_reporting'];
            $refPrice = (string) $row['ref_price_local'];
            $lotSize = (int) $row['lot_size'];
            $buyFeePct = (string) $row['_buy_fee_pct'];
            $slippagePct = (string) $row['_slippage_pct'];

            $allocLoc = Dec::div($allocRep, $fxRate, 6) ?? '0';
            $effectiveShareCostLoc = Dec::mul(
                $refPrice,
                Dec::add(Dec::add('1', $buyFeePct, 8), $slippagePct, 8),
                6,
            );
            $lotCostLoc = Dec::mul($effectiveShareCostLoc, (string) $lotSize, 6);
            $buyLots = Dec::cmp($lotCostLoc, '0') > 0
                ? max(0, (int) (string) Dec::of($allocLoc)->dividedBy($lotCostLoc, 0, RoundingMode::DOWN))
                : 0;

            if ($buyLots > 0) {
                $buyShares = (string) ($buyLots * $lotSize);
                $grossLoc = Dec::mul($buyShares, $refPrice, 4);
                $feeLoc = Dec::mul($grossLoc, $buyFeePct, 4);
                $totalOutflowLoc = Dec::add($grossLoc, $feeLoc, 4);

                $grossRep = Dec::mul($grossLoc, $fxRate, 4);
                $feeRep = Dec::mul($feeLoc, $fxRate, 4);
                $outflowRep = Dec::mul($totalOutflowLoc, $fxRate, 4);

                $postShares = Dec::add((string) $row['held_shares'], $buyShares, 6);
                $postMvRep = Dec::mul(Dec::mul($postShares, $refPrice, 4), $fxRate, 4);

                $row['action'] = 'buy';
                $row['suggested_lots'] = $buyLots;
                $row['suggested_shares'] = Dec::round($buyShares, 6);
                $row['estimated_gross_local'] = $grossLoc;
                $row['estimated_gross_rep'] = $grossRep;
                $row['estimated_fee_rep'] = $feeRep;
                $row['net_cash_impact_rep'] = Dec::mul($outflowRep, '-1', 4);
                $row['post_shares'] = $postShares;
                $row['post_mv_rep'] = $postMvRep;

                $remainingCashRep = Dec::max('0.0000', Dec::sub($remainingCashRep, $outflowRep, 4));
                $totalBuyGrossRep = Dec::add($totalBuyGrossRep, $grossRep, 4);
                $totalFeesRep = Dec::add($totalFeesRep, $feeRep, 4);
            } else {
                $row['action'] = 'hold_lot_rounding';
            }
            unset($row);
        }

        // Compute Post-Trade totals, weights, and Pre vs Post HHI
        $netCashFlowAllTradesRep = '0.0000';
        $preEquityMvSum = '0.0000';
        $postEquityMvSum = '0.0000';

        foreach ($rows as $row) {
            $netCashFlowAllTradesRep = Dec::add($netCashFlowAllTradesRep, (string) $row['net_cash_impact_rep'], 4);
            $preEquityMvSum = Dec::add($preEquityMvSum, (string) $row['current_mv_rep'], 4);
            $postEquityMvSum = Dec::add($postEquityMvSum, (string) $row['post_mv_rep'], 4);
        }

        $postCashRep = Dec::add(Dec::add($currentCashRep, $inflowRep, 4), $netCashFlowAllTradesRep, 4);
        $postTotalEquityRep = Dec::add($postEquityMvSum, $postCashRep, 4);

        $finalRows = [];
        foreach ($rows as $row) {
            unset($row['_buy_fee_pct'], $row['_slippage_pct'], $row['_avg_cost_local']);
            $postW = Dec::cmp($postTotalEquityRep, '0') > 0
                ? (float) (Dec::div(Dec::mul((string) $row['post_mv_rep'], '100', 8), $postTotalEquityRep, 4) ?? '0')
                : 0.0;
            $row['post_weight_pct'] = number_format($postW, 2, '.', '');
            $row['post_drift_pct'] = number_format($postW - (float) $row['target_weight_pct'], 2, '.', '');
            $finalRows[] = $row;
        }

        usort($finalRows, fn ($a, $b) => Dec::cmp($b['target_weight_pct'], $a['target_weight_pct']) ?: Dec::cmp($b['current_mv_rep'], $a['current_mv_rep']));

        $preHhi = $this->computeHoldingsHhi($finalRows, 'current_mv_rep', $preEquityMvSum);
        $postHhi = $this->computeHoldingsHhi($finalRows, 'post_mv_rep', $postEquityMvSum);

        $twoWayTraded = Dec::add($totalBuyGrossRep, $totalSellGrossRep, 4);
        $turnoverPct = Dec::cmp($postInflowEquityRep, '0') > 0
            ? (Dec::div($twoWayTraded, Dec::mul($postInflowEquityRep, '2', 4), 8) ?? '0.00000000')
            : '0.00000000';

        return [
            'has_data' => ! empty($finalRows),
            'is_default_preview' => $isDefaultPreview,
            'reporting_currency' => $targetCcy,
            'prefs' => $prefs,
            'additional_cash_inflow_rep' => $inflowRep,
            'allocated_target_pct' => number_format($sumTargetWeightPct, 2, '.', ''),
            'cash_buffer_pct' => number_format($cashBufferPct, 2, '.', ''),
            'total_target_plus_cash_pct' => number_format($sumTargetWeightPct + $cashBufferPct, 2, '.', ''),
            'over_allocated' => $overAllocated,
            'buys_scaled_by_cash' => $buysScaledByCash,
            'safety_blocked_count' => $safetyBlockedCount,
            'pre_total_equity_rep' => $currentEquityRep,
            'post_inflow_equity_rep' => $postInflowEquityRep,
            'pre_cash_rep' => $currentCashRep,
            'target_cash_rep' => $targetCashRep,
            'post_cash_rep' => $postCashRep,
            'post_total_equity_rep' => $postTotalEquityRep,
            'total_buy_gross_rep' => $totalBuyGrossRep,
            'total_sell_gross_rep' => $totalSellGrossRep,
            'total_estimated_fees_rep' => $totalFeesRep,
            'turnover_pct' => $turnoverPct,
            'pre_hhi' => $preHhi,
            'post_hhi' => $postHhi,
            'rows' => $finalRows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $holdings
     * @return array<int, float>
     */
    private function buildCurrentProportionalTargets(array $holdings, float $cashBufferPct): array
    {
        $totalMv = '0.0000';
        foreach ($holdings as $h) {
            $totalMv = Dec::add($totalMv, (string) ($h['market_value_reporting'] ?? '0'), 4);
        }
        if (Dec::cmp($totalMv, '0') <= 0) {
            return [];
        }

        $investablePct = max(0.0, 100.0 - $cashBufferPct);
        $targets = [];
        $runningSum = 0.0;
        $lastId = null;

        foreach ($holdings as $h) {
            $id = (int) $h['instrument_id'];
            $mv = (string) ($h['market_value_reporting'] ?? '0');
            $share = (float) (Dec::div($mv, $totalMv, 8) ?? '0');
            $w = round($share * $investablePct, 2);
            $targets[$id] = $w;
            $runningSum += $w;
            $lastId = $id;
        }

        // Adjust rounding delta on largest/last item so preview sums neatly to investablePct
        if ($lastId !== null && $investablePct > 0.0) {
            $delta = round($investablePct - $runningSum, 2);
            $targets[$lastId] = max(0.0, round($targets[$lastId] + $delta, 2));
        }

        return $targets;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function computeHoldingsHhi(array $rows, string $field, string $totalEqMv): ?string
    {
        if (Dec::cmp($totalEqMv, '0') <= 0) {
            return null;
        }

        $hhi = '0.000000';
        foreach ($rows as $row) {
            $mv = (string) ($row[$field] ?? '0');
            if (Dec::cmp($mv, '0') <= 0) {
                continue;
            }
            $w = Dec::div(Dec::mul($mv, '100', 8), $totalEqMv, 6) ?? '0';
            $hhi = Dec::add($hhi, Dec::mul($w, $w, 6), 6);
        }

        return Dec::round($hhi, 2);
    }
}
