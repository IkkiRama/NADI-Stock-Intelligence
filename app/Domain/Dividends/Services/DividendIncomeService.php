<?php

namespace App\Domain\Dividends\Services;

use App\Domain\MarketData\Services\BatchFxLookup;
use App\Models\BrokerageAccount;
use App\Models\CorporateAction;
use App\Models\Instrument;
use App\Models\InstrumentFundamentalLatest;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\User;
use App\Support\Math\Dec;
use Carbon\CarbonImmutable;

/**
 * Dividends & Passive Income Analytics Engine.
 *
 * Strictly distinguishes historical ledger-realized dividends (`dividend_receipt`,
 * `dividend_tax`) from forward 12-month projected cash-flow estimates derived from
 * declared corporate actions or trailing fundamental dividend yields.
 */
final class DividendIncomeService
{
    /**
     * @param  array<string, mixed>  $valuationSummary
     * @return array<string, mixed>
     */
    public function analyze(
        User $user,
        Portfolio $portfolio,
        array $valuationSummary,
        ?int $accountId = null,
        ?float $overrideTaxPct = null,
    ): array {
        $summary = $valuationSummary['summary'] ?? [];
        $holdings = $valuationSummary['consolidatedHoldings'] ?? [];
        $targetCcy = strtoupper((string) ($summary['reporting_currency'] ?? $portfolio->reporting_currency ?: 'IDR'));
        $fxLookup = BatchFxLookup::load();

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $todayStr = $today->toDateString();
        $ytdStart = $today->startOfYear()->toDateString();
        $trailing12mStart = $today->subYearsNoOverflow(1)->toDateString();

        $accountIds = BrokerageAccount::query()
            ->where('user_id', $user->id)
            ->where('portfolio_id', $portfolio->id)
            ->where('is_active', true)
            ->when($accountId !== null, fn ($q) => $q->where('id', $accountId))
            ->pluck('id')
            ->all();

        $txRows = empty($accountIds)
            ? collect()
            : PortfolioTransaction::query()
                ->with(['instrument.exchange:id,code', 'account:id,account_name'])
                ->where('user_id', $user->id)
                ->where('portfolio_id', $portfolio->id)
                ->whereIn('brokerage_account_id', $accountIds)
                ->whereNull('reversed_at')
                ->whereIn('transaction_type', ['dividend_receipt', 'dividend_tax'])
                ->orderByDesc('trade_date')
                ->orderByDesc('id')
                ->get();

        // Build trailing 12-month calendar month map (YYYY-MM)
        $trailingMonths = [];
        for ($i = 11; $i >= 0; $i--) {
            $ym = $today->subMonthsNoOverflow($i)->format('Y-m');
            $trailingMonths[$ym] = '0.0000';
        }

        $realizedYtdRep = '0.0000';
        $realized12mRep = '0.0000';
        $realizedAllTimeRep = '0.0000';
        $realizedTaxAllTimeRep = '0.0000';
        $realizedByInstRep = [];
        $ledgerHistory = [];
        $anyDemo = (bool) ($summary['is_demo'] ?? false);

        foreach ($txRows as $tx) {
            $anyDemo = $anyDemo || (bool) $tx->is_demo;
            $d = $tx->trade_date->toDateString();
            $ym = substr($d, 0, 7);
            $txCcy = strtoupper((string) $tx->transaction_currency);
            $fx = $fxLookup->resolve($txCcy, $targetCcy, $d) ?? '1.00000000';

            if ($tx->transaction_type === 'dividend_receipt') {
                $grossTx = (string) $tx->gross_amount;
                $taxTx = (string) $tx->tax_amount;
                $netTx = (string) $tx->net_cash_amount;
                $grossRep = Dec::mul($grossTx, $fx, 4);
                $taxRep = Dec::mul($taxTx, $fx, 4);
                $netRep = Dec::mul($netTx, $fx, 4);
            } else {
                // dividend_tax has negative net_cash_amount reducing cash balance
                $absTaxTx = Dec::cmp((string) $tx->net_cash_amount, '0') < 0
                    ? Dec::mul((string) $tx->net_cash_amount, '-1', 4)
                    : (string) $tx->gross_amount;
                $grossTx = '0.0000';
                $taxTx = $absTaxTx;
                $netTx = Dec::mul($absTaxTx, '-1', 4);
                $grossRep = '0.0000';
                $taxRep = Dec::mul($absTaxTx, $fx, 4);
                $netRep = Dec::mul($netTx, $fx, 4);
            }

            $realizedAllTimeRep = Dec::add($realizedAllTimeRep, $netRep, 4);
            $realizedTaxAllTimeRep = Dec::add($realizedTaxAllTimeRep, $taxRep, 4);
            if ($d >= $ytdStart) {
                $realizedYtdRep = Dec::add($realizedYtdRep, $netRep, 4);
            }
            if ($d >= $trailing12mStart) {
                $realized12mRep = Dec::add($realized12mRep, $netRep, 4);
            }
            if (array_key_exists($ym, $trailingMonths)) {
                $trailingMonths[$ym] = Dec::add($trailingMonths[$ym], $netRep, 4);
            }
            if ($tx->instrument_id !== null) {
                $realizedByInstRep[$tx->instrument_id] = Dec::add(
                    $realizedByInstRep[$tx->instrument_id] ?? '0.0000',
                    $netRep,
                    4,
                );
            }

            $ledgerHistory[] = [
                'id' => $tx->id,
                'trade_date' => $d,
                'transaction_type' => $tx->transaction_type,
                'account_name' => $tx->account?->account_name ?? '',
                'instrument_id' => $tx->instrument_id,
                'symbol' => $tx->instrument?->symbol,
                'exchange_code' => $tx->instrument?->exchange?->code,
                'currency' => $txCcy,
                'gross_amount' => $grossTx,
                'tax_amount' => $taxTx,
                'net_amount' => $netTx,
                'net_amount_reporting' => $netRep,
                'notes' => $tx->notes,
                'is_demo' => (bool) $tx->is_demo,
            ];
        }

        // Batch-load CorporateActions & latest fundamentals for held instruments
        $heldIds = array_map(fn ($h) => (int) $h['instrument_id'], $holdings);
        $corpActions = empty($heldIds)
            ? collect()
            : CorporateAction::query()
                ->whereIn('instrument_id', $heldIds)
                ->where('action_type', 'cash_dividend')
                ->orderByDesc('ex_date')
                ->get()
                ->groupBy('instrument_id');

        $fundamentalsByInst = empty($heldIds)
            ? collect()
            : InstrumentFundamentalLatest::query()
                ->whereIn('instrument_id', $heldIds)
                ->get(['instrument_id', 'dividend_yield', 'dividend_payout_ratio'])
                ->keyBy('instrument_id');

        // Build forward 12-month projected schedule (next 12 calendar months)
        $forwardMonths = [];
        for ($i = 0; $i < 12; $i++) {
            $ym = $today->addMonthsNoOverflow($i)->format('Y-m');
            $forwardMonths[$ym] = [
                'month' => $ym,
                'confirmed_net_rep' => '0.0000',
                'estimated_net_rep' => '0.0000',
                'total_projected_net_rep' => '0.0000',
            ];
        }

        $projectedAnnualGrossRep = '0.0000';
        $projectedAnnualNetRep = '0.0000';
        $payingHoldingsCount = 0;
        $holdingDividends = [];

        $taxRatesConfig = config('nadi.dividend_withholding_tax', []);
        $defaultTaxRate = (string) ($taxRatesConfig['DEFAULT'] ?? '0.10');

        foreach ($holdings as $h) {
            $instId = (int) $h['instrument_id'];
            $instCcy = strtoupper((string) $h['currency']);
            $country = strtoupper((string) ($h['country_code'] ?? ''));
            $qty = (string) $h['quantity'];
            $close = $h['close'] !== null ? (string) $h['close'] : null;
            $mvRep = (string) ($h['market_value_reporting'] ?? '0.0000');
            $costRep = (string) ($h['total_cost_reporting'] ?? '0.0000');

            $fxNow = $fxLookup->resolve($instCcy, $targetCcy, $todayStr) ?? '1.00000000';
            $actions = $corpActions->get($instId, collect());
            $fund = $fundamentalsByInst->get($instId);

            // 1. Determine trailing 12M DPS from CorporateActions (prefer actual recent distributions)
            $trailingDivSumLoc = '0.000000';
            $trailingEvents = [];
            $upcomingEvents = [];
            foreach ($actions as $act) {
                $exStr = $act->ex_date->toDateString();
                $amt = $act->cash_amount !== null ? (string) $act->cash_amount : '0';
                if (Dec::cmp($amt, '0') <= 0) {
                    continue;
                }
                $declCcy = strtoupper((string) ($act->declared_currency ?: $instCcy));
                $fxDecl = $declCcy === $instCcy ? '1.00000000' : ($fxLookup->resolve($declCcy, $instCcy, $exStr) ?? '1.00000000');
                $amtInst = Dec::mul($amt, $fxDecl, 6);

                if ($exStr >= $todayStr) {
                    $upcomingEvents[] = ['action' => $act, 'dps_local' => $amtInst];
                } elseif ($exStr >= $trailing12mStart) {
                    $trailingDivSumLoc = Dec::add($trailingDivSumLoc, $amtInst, 6);
                    $trailingEvents[] = ['action' => $act, 'dps_local' => $amtInst];
                }
            }

            $estimationBasis = 'none';
            $estAnnualDpsLoc = '0.000000';
            if (Dec::cmp($trailingDivSumLoc, '0') > 0) {
                $estAnnualDpsLoc = $trailingDivSumLoc;
                $estimationBasis = 'trailing_corp_actions';
            } elseif (! empty($upcomingEvents)) {
                foreach ($upcomingEvents as $ue) {
                    $estAnnualDpsLoc = Dec::add($estAnnualDpsLoc, $ue['dps_local'], 6);
                }
                $estimationBasis = 'declared_upcoming';
            } elseif ($fund && $fund->dividend_yield !== null && Dec::cmp((string) $fund->dividend_yield, '0') > 0 && $close !== null) {
                $estAnnualDpsLoc = Dec::mul($close, (string) $fund->dividend_yield, 6);
                $estimationBasis = 'fundamental_yield';
            }

            $taxRate = $overrideTaxPct !== null
                ? Dec::round(number_format(max(0.0, min(50.0, $overrideTaxPct)) / 100.0, 6, '.', ''), 4)
                : (string) ($taxRatesConfig[$country] ?? $defaultTaxRate);

            $projGrossLoc = Dec::mul($qty, $estAnnualDpsLoc, 4);
            $projTaxLoc = Dec::mul($projGrossLoc, $taxRate, 4);
            $projNetLoc = Dec::sub($projGrossLoc, $projTaxLoc, 4);

            $projGrossRep = Dec::mul($projGrossLoc, $fxNow, 4);
            $projNetRep = Dec::mul($projNetLoc, $fxNow, 4);

            if (Dec::cmp($projGrossRep, '0') > 0) {
                $payingHoldingsCount++;
                $projectedAnnualGrossRep = Dec::add($projectedAnnualGrossRep, $projGrossRep, 4);
                $projectedAnnualNetRep = Dec::add($projectedAnnualNetRep, $projNetRep, 4);
            }

            $yieldOnMv = Dec::cmp($mvRep, '0') > 0 ? Dec::div($projGrossRep, $mvRep, 8) : null;
            $yieldOnCost = Dec::cmp($costRep, '0') > 0 ? Dec::div($projGrossRep, $costRep, 8) : null;
            $payoutRatio = $fund?->dividend_payout_ratio !== null ? (string) $fund->dividend_payout_ratio : null;
            $highPayoutFlag = $payoutRatio !== null && Dec::cmp($payoutRatio, '0.85') > 0;

            // Distribute projected payouts across next 12 calendar months
            if (Dec::cmp($projNetRep, '0') > 0) {
                $assigned = false;
                foreach ($upcomingEvents as $ue) {
                    $payDate = $ue['action']->payment_date ?? $ue['action']->ex_date;
                    $ym = $payDate->format('Y-m');
                    if (isset($forwardMonths[$ym])) {
                        $netPieceRep = Dec::mul(
                            Dec::mul(Dec::mul($qty, $ue['dps_local'], 4), Dec::sub('1', $taxRate, 4), 4),
                            $fxNow,
                            4,
                        );
                        $forwardMonths[$ym]['confirmed_net_rep'] = Dec::add($forwardMonths[$ym]['confirmed_net_rep'], $netPieceRep, 4);
                        $forwardMonths[$ym]['total_projected_net_rep'] = Dec::add($forwardMonths[$ym]['total_projected_net_rep'], $netPieceRep, 4);
                        $assigned = true;
                    }
                }

                if (! $assigned) {
                    if (! empty($trailingEvents)) {
                        $perEventNet = Dec::div($projNetRep, (string) count($trailingEvents), 4) ?? '0.0000';
                        foreach ($trailingEvents as $te) {
                            $refDate = $te['action']->payment_date ?? $te['action']->ex_date;
                            $nextYm = $refDate->addYearsNoOverflow(1)->format('Y-m');
                            if (! isset($forwardMonths[$nextYm])) {
                                $nextYm = $today->addMonthsNoOverflow(3)->format('Y-m');
                            }
                            $forwardMonths[$nextYm]['estimated_net_rep'] = Dec::add($forwardMonths[$nextYm]['estimated_net_rep'], $perEventNet, 4);
                            $forwardMonths[$nextYm]['total_projected_net_rep'] = Dec::add($forwardMonths[$nextYm]['total_projected_net_rep'], $perEventNet, 4);
                        }
                    } elseif ($country === 'US') {
                        // Quarterly US dividend cadence estimation (M+2, M+5, M+8, M+11)
                        $qNet = Dec::div($projNetRep, '4', 4) ?? '0.0000';
                        foreach ([2, 5, 8, 11] as $offset) {
                            $ym = $today->addMonthsNoOverflow($offset)->format('Y-m');
                            $forwardMonths[$ym]['estimated_net_rep'] = Dec::add($forwardMonths[$ym]['estimated_net_rep'], $qNet, 4);
                            $forwardMonths[$ym]['total_projected_net_rep'] = Dec::add($forwardMonths[$ym]['total_projected_net_rep'], $qNet, 4);
                        }
                    } else {
                        $ym = $today->addMonthsNoOverflow(4)->format('Y-m');
                        $forwardMonths[$ym]['estimated_net_rep'] = Dec::add($forwardMonths[$ym]['estimated_net_rep'], $projNetRep, 4);
                        $forwardMonths[$ym]['total_projected_net_rep'] = Dec::add($forwardMonths[$ym]['total_projected_net_rep'], $projNetRep, 4);
                    }
                }
            }

            $holdingDividends[] = [
                'instrument_id' => $instId,
                'symbol' => $h['symbol'],
                'name' => $h['name'],
                'exchange_code' => $h['exchange_code'],
                'country_code' => $country,
                'currency' => $instCcy,
                'quantity' => $qty,
                'market_value_rep' => $mvRep,
                'total_cost_rep' => $costRep,
                'estimated_annual_dps_local' => $estAnnualDpsLoc,
                'tax_rate_pct' => Dec::mul($taxRate, '100', 2),
                'projected_annual_gross_rep' => $projGrossRep,
                'projected_annual_net_rep' => $projNetRep,
                'realized_net_rep' => $realizedByInstRep[$instId] ?? '0.0000',
                'yield_on_mv' => $yieldOnMv,
                'yield_on_cost' => $yieldOnCost,
                'payout_ratio' => $payoutRatio,
                'high_payout_flag' => $highPayoutFlag,
                'safeguard_triggered' => (bool) ($h['safeguard_triggered'] ?? false),
                'estimation_basis' => $estimationBasis,
            ];
        }

        usort($holdingDividends, fn ($a, $b) => Dec::cmp($b['projected_annual_net_rep'], $a['projected_annual_net_rep']));

        $portfolioMvRep = (string) ($summary['market_value'] ?? '0.0000');
        $portfolioCostRep = (string) ($summary['invested_cost'] ?? '0.0000');
        $portfolioYieldOnMv = Dec::cmp($portfolioMvRep, '0') > 0
            ? Dec::div($projectedAnnualGrossRep, $portfolioMvRep, 8)
            : null;
        $portfolioYieldOnCost = Dec::cmp($portfolioCostRep, '0') > 0
            ? Dec::div($projectedAnnualGrossRep, $portfolioCostRep, 8)
            : null;
        $projectedMonthlyAvgRep = Dec::div($projectedAnnualNetRep, '12', 4) ?? '0.0000';

        // Upcoming declared dividend calendar across market universe (marking held positions)
        $heldMap = collect($holdings)->keyBy('instrument_id');
        $upcomingCalendar = CorporateAction::query()
            ->with('instrument.exchange:id,code')
            ->where('action_type', 'cash_dividend')
            ->whereDate('ex_date', '>=', $todayStr)
            ->orderBy('ex_date')
            ->limit(25)
            ->get()
            ->filter(fn ($a) => $a->instrument !== null)
            ->map(function (CorporateAction $a) use ($heldMap, $fxLookup, $targetCcy, $todayStr): array {
                $inst = $a->instrument;
                $h = $heldMap->get($inst->id);
                $declCcy = strtoupper((string) ($a->declared_currency ?: $inst->currency));
                $amt = $a->cash_amount !== null ? (string) $a->cash_amount : '0';
                $estGrossRep = null;
                if ($h && Dec::cmp($amt, '0') > 0) {
                    $fx = $fxLookup->resolve($declCcy, $targetCcy, $todayStr) ?? '1.00000000';
                    $estGrossRep = Dec::mul(Dec::mul((string) $h['quantity'], $amt, 4), $fx, 4);
                }

                return [
                    'id' => $a->id,
                    'instrument_id' => $inst->id,
                    'symbol' => $inst->symbol,
                    'name' => $inst->name,
                    'exchange_code' => $inst->exchange->code,
                    'ex_date' => $a->ex_date->toDateString(),
                    'payment_date' => $a->payment_date?->toDateString(),
                    'declared_currency' => $declCcy,
                    'cash_amount' => $a->cash_amount !== null ? (string) $a->cash_amount : null,
                    'is_held' => $h !== null,
                    'held_shares' => $h ? (string) $h['quantity'] : null,
                    'estimated_gross_rep' => $estGrossRep,
                    'is_demo' => (bool) $a->is_demo,
                ];
            })
            ->values()
            ->all();

        $monthlyRealizedChart = [];
        foreach ($trailingMonths as $ym => $val) {
            $monthlyRealizedChart[] = ['month' => $ym, 'realized_net_rep' => $val];
        }

        return [
            'reporting_currency' => $targetCcy,
            'override_tax_pct' => $overrideTaxPct,
            'is_demo' => $anyDemo,
            'summary' => [
                'realized_net_ytd_rep' => $realizedYtdRep,
                'realized_net_12m_rep' => $realized12mRep,
                'realized_net_all_time_rep' => $realizedAllTimeRep,
                'realized_tax_all_time_rep' => $realizedTaxAllTimeRep,
                'projected_annual_gross_rep' => $projectedAnnualGrossRep,
                'projected_annual_net_rep' => $projectedAnnualNetRep,
                'projected_monthly_avg_rep' => $projectedMonthlyAvgRep,
                'portfolio_yield_on_mv' => $portfolioYieldOnMv,
                'portfolio_yield_on_cost' => $portfolioYieldOnCost,
                'paying_holdings_count' => $payingHoldingsCount,
                'total_holdings_count' => count($holdings),
            ],
            'monthly_realized' => $monthlyRealizedChart,
            'monthly_projected' => array_values($forwardMonths),
            'holdings' => $holdingDividends,
            'upcoming_calendar' => $upcomingCalendar,
            'ledger_history' => $ledgerHistory,
        ];
    }
}
