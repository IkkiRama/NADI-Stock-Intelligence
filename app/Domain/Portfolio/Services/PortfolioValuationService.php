<?php

namespace App\Domain\Portfolio\Services;

use App\Domain\MarketData\Services\FxConversionService;
use App\Models\BrokerageAccount;
use App\Models\Instrument;
use App\Models\Portfolio;
use App\Models\PortfolioHolding;
use App\Models\User;
use App\Support\Math\Dec;

/**
 * Multi-currency Consolidated & Account-Level Portfolio Valuation Service.
 */
final class PortfolioValuationService
{
    public function __construct(
        private readonly FxConversionService $fx,
    ) {}

    /**
     * Summarize portfolio valuation, cash, realized/unrealized P&L, dividends, and holdings.
     *
     * @return array<string, mixed>
     */
    public function summarize(
        User $user,
        ?Portfolio $portfolio = null,
        ?int $accountId = null,
        ?string $reportingCurrency = null,
        ?string $costBasisMethod = null,
    ): array {
        $portfolio ??= $user->ensureDefaultPortfolio();
        $targetCcy = strtoupper($reportingCurrency ?: $portfolio->reporting_currency ?: 'IDR');
        $method = in_array($costBasisMethod, ['weighted_average', 'fifo'], true)
            ? $costBasisMethod
            : ($portfolio->cost_basis_method ?: 'weighted_average');

        $accountsQuery = BrokerageAccount::query()
            ->with('broker:id,code,name')
            ->where('user_id', $user->id)
            ->where('portfolio_id', $portfolio->id)
            ->where('is_active', true)
            ->orderBy('id');

        $allAccounts = $accountsQuery->get();
        $filteredAccounts = $accountId
            ? $allAccounts->where('id', $accountId)->values()
            : $allAccounts;

        $allHoldingRows = PortfolioHolding::query()
            ->with([
                'instrument.exchange:id,code',
                'instrument.latestQuote',
                'instrument.latestFundamentals:instrument_id,market_cap_idr',
                'instrument.safetyAssessment',
                'account.broker:id,code,name',
            ])
            ->where('user_id', $user->id)
            ->where('portfolio_id', $portfolio->id)
            ->get();

        $holdingRows = $accountId
            ? $allHoldingRows->where('brokerage_account_id', $accountId)->values()
            : $allHoldingRows;

        $totalCashTarget = '0.0000';
        $totalNetContribTarget = '0.0000';
        $totalRealizedTarget = '0.0000';
        $totalDividendsTarget = '0.0000';
        $totalFeesTarget = '0.0000';
        $missingFx = [];
        $anyDemo = false;

        // Pre-aggregate per-account equity market value & cost across all accounts in reporting currency
        $acctEquityMv = [];
        $acctCostRep = [];
        $acctHoldingCnt = [];
        foreach ($allHoldingRows as $ah) {
            $ainst = $ah->instrument;
            if (! $ainst) {
                continue;
            }
            $aClose = $ainst->latestQuote ? (string) $ainst->latestQuote->close : null;
            $aCostLocal = $method === 'fifo' ? (string) $ah->total_cost_fifo : (string) $ah->total_cost_avg;
            $aMvLocal = $aClose !== null ? Dec::mul((string) $ah->quantity, $aClose, 4) : $aCostLocal;
            $aFx = $this->fx->rate($ainst->currency, $targetCcy) ?? '0';

            $aid = $ah->brokerage_account_id;
            $acctEquityMv[$aid] = Dec::add($acctEquityMv[$aid] ?? '0.0000', Dec::mul($aMvLocal, $aFx, 4), 4);
            $acctCostRep[$aid] = Dec::add($acctCostRep[$aid] ?? '0.0000', Dec::mul($aCostLocal, $aFx, 4), 4);
            $acctHoldingCnt[$aid] = ($acctHoldingCnt[$aid] ?? 0) + 1;
        }

        $serializedAccounts = [];
        $accountComparison = [];
        $wholePortfolioEquity = '0.0000';

        foreach ($allAccounts as $acct) {
            $anyDemo = $anyDemo || (bool) $acct->is_demo;
            $fxAcct = $this->fx->rate($acct->base_currency, $targetCcy);
            if ($fxAcct === null) {
                $missingFx[] = "{$acct->base_currency}/{$targetCcy}";
            }

            $realizedAcct = $method === 'fifo' ? (string) $acct->realized_pnl_fifo : (string) $acct->realized_pnl_avg;
            $mult = $fxAcct ?? '0';
            $cashRep = Dec::mul((string) $acct->cash_balance, $mult, 4);
            $contribRep = Dec::mul((string) $acct->net_contributions, $mult, 4);
            $realizedRep = Dec::mul($realizedAcct, $mult, 4);
            $divRep = Dec::mul((string) $acct->dividends_received, $mult, 4);
            $feesRep = Dec::mul((string) $acct->fees_paid, $mult, 4);

            $eqMvRep = $acctEquityMv[$acct->id] ?? '0.0000';
            $eqCostRep = $acctCostRep[$acct->id] ?? '0.0000';
            $acctTotalEquity = Dec::add($eqMvRep, $cashRep, 4);
            $acctUnrlRep = Dec::sub($eqMvRep, $eqCostRep, 4);
            $acctUnrlPct = Dec::cmp($eqCostRep, '0') > 0 ? Dec::div($acctUnrlRep, $eqCostRep, 8) : null;
            $acctTotalPnl = Dec::add(Dec::add($realizedRep, $acctUnrlRep, 4), $divRep, 4);
            $wholePortfolioEquity = Dec::add($wholePortfolioEquity, $acctTotalEquity, 4);

            $included = ! $accountId || $acct->id === $accountId;
            if ($included) {
                $totalCashTarget = Dec::add($totalCashTarget, $cashRep, 4);
                $totalNetContribTarget = Dec::add($totalNetContribTarget, $contribRep, 4);
                $totalRealizedTarget = Dec::add($totalRealizedTarget, $realizedRep, 4);
                $totalDividendsTarget = Dec::add($totalDividendsTarget, $divRep, 4);
                $totalFeesTarget = Dec::add($totalFeesTarget, $feesRep, 4);
            }

            $serializedAccounts[] = [
                'id' => $acct->id,
                'account_name' => $acct->account_name,
                'account_label' => $acct->account_label,
                'broker_code' => $acct->broker?->code ?? 'MANUAL',
                'broker_name' => $acct->broker?->name ?? 'Manual',
                'base_currency' => $acct->base_currency,
                'cost_basis_method' => $acct->cost_basis_method,
                'cash_balance' => (string) $acct->cash_balance,
                'net_contributions' => (string) $acct->net_contributions,
                'realized_pnl' => $realizedAcct,
                'dividends_received' => (string) $acct->dividends_received,
                'fees_paid' => (string) $acct->fees_paid,
                'is_demo' => (bool) $acct->is_demo,
                'last_reconciled_at' => $acct->last_reconciled_at?->toIso8601String(),
            ];

            $accountComparison[] = [
                'id' => $acct->id,
                'account_name' => $acct->account_name,
                'broker_code' => $acct->broker?->code ?? 'MANUAL',
                'broker_name' => $acct->broker?->name ?? 'Manual',
                'base_currency' => $acct->base_currency,
                'included' => $included,
                'holdings_count' => $acctHoldingCnt[$acct->id] ?? 0,
                'cash_balance_reporting' => $cashRep,
                'market_value_reporting' => $eqMvRep,
                'total_cost_reporting' => $eqCostRep,
                'total_equity_reporting' => $acctTotalEquity,
                'net_contributions_reporting' => $contribRep,
                'unrealized_pnl_reporting' => $acctUnrlRep,
                'unrealized_pnl_pct' => $acctUnrlPct,
                'realized_pnl_reporting' => $realizedRep,
                'dividends_received_reporting' => $divRep,
                'fees_paid_reporting' => $feesRep,
                'total_pnl_reporting' => $acctTotalPnl,
                'is_demo' => (bool) $acct->is_demo,
            ];
        }

        foreach ($accountComparison as &$ac) {
            $ac['portfolio_share_pct'] = Dec::cmp($wholePortfolioEquity, '0') > 0
                ? (Dec::div(Dec::mul($ac['total_equity_reporting'], '100', 6), $wholePortfolioEquity, 2) ?? '0.00')
                : '0.00';
        }
        unset($ac);

        $totalMarketValueTarget = '0.0000';
        $totalCostTarget = '0.0000';
        $totalDailyChangeTarget = '0.0000';
        $latestQuoteDate = null;
        $latestSyncedAt = null;

        $accountHoldings = [];
        $consolidatedMap = [];
        $safetyFlaggedIds = [];

        foreach ($holdingRows as $h) {
            $inst = $h->instrument;
            if (! $inst) {
                continue;
            }
            $anyDemo = $anyDemo || (bool) $h->is_demo;
            $quote = $inst->latestQuote;
            $safety = $inst->safetyAssessment;
            if ($safety?->safeguard_triggered) {
                $safetyFlaggedIds[$inst->id] = true;
            }

            $close = $quote ? (string) $quote->close : null;
            $dailyPerShare = $quote?->change !== null ? (string) $quote->change : '0';
            $asOf = $quote?->trade_date?->toDateString();
            if ($asOf && ($latestQuoteDate === null || $asOf > $latestQuoteDate)) {
                $latestQuoteDate = $asOf;
            }
            $refreshedIso = $quote?->refreshed_at?->toIso8601String();
            if ($refreshedIso && ($latestSyncedAt === null || $refreshedIso > $latestSyncedAt)) {
                $latestSyncedAt = $refreshedIso;
            }

            $mcapIdr = $inst->latestFundamentals?->market_cap_idr !== null ? (string) $inst->latestFundamentals->market_cap_idr : null;
            $sizeTier = match (true) {
                $mcapIdr === null => 'unclassified',
                Dec::cmp($mcapIdr, '100000000000000') >= 0 => 'large_cap',
                Dec::cmp($mcapIdr, '10000000000000') >= 0 => 'mid_cap',
                Dec::cmp($mcapIdr, '0') > 0 => 'small_cap',
                default => 'unclassified',
            };

            $qty = (string) $h->quantity;
            $costTotal = $method === 'fifo' ? (string) $h->total_cost_fifo : (string) $h->total_cost_avg;
            $costPerShare = $method === 'fifo' ? (string) $h->fifo_cost_per_share : (string) $h->avg_cost_per_share;

            $marketValue = $close !== null ? Dec::mul($qty, $close, 4) : null;
            $unrealizedPnl = $marketValue !== null ? Dec::sub($marketValue, $costTotal, 4) : null;
            $unrealizedPct = ($unrealizedPnl !== null && Dec::cmp($costTotal, '0') > 0)
                ? Dec::div($unrealizedPnl, $costTotal, 8)
                : null;
            $dailyChange = $close !== null ? Dec::mul($qty, $dailyPerShare, 4) : null;

            $fxToTarget = $this->fx->rate($inst->currency, $targetCcy);
            if ($fxToTarget === null) {
                $missingFx[] = "{$inst->currency}/{$targetCcy}";
            }

            $mvTarget = ($marketValue !== null && $fxToTarget !== null) ? Dec::mul($marketValue, $fxToTarget, 4) : null;
            $costTarget = $fxToTarget !== null ? Dec::mul($costTotal, $fxToTarget, 4) : null;
            $unrlTarget = ($unrealizedPnl !== null && $fxToTarget !== null) ? Dec::mul($unrealizedPnl, $fxToTarget, 4) : null;
            $dailyTarget = ($dailyChange !== null && $fxToTarget !== null) ? Dec::mul($dailyChange, $fxToTarget, 4) : null;

            if ($mvTarget !== null) {
                $totalMarketValueTarget = Dec::add($totalMarketValueTarget, $mvTarget, 4);
            }
            if ($costTarget !== null) {
                $totalCostTarget = Dec::add($totalCostTarget, $costTarget, 4);
            }
            if ($dailyTarget !== null) {
                $totalDailyChangeTarget = Dec::add($totalDailyChangeTarget, $dailyTarget, 4);
            }

            $accountHoldings[] = [
                'id' => $h->id,
                'brokerage_account_id' => $h->brokerage_account_id,
                'account_name' => $h->account?->account_name ?? '',
                'instrument_id' => $inst->id,
                'symbol' => $inst->symbol,
                'name' => $inst->name,
                'exchange_code' => $inst->exchange->code,
                'sector' => $inst->sector,
                'industry' => $inst->industry,
                'country_code' => $inst->country_code,
                'size_tier' => $sizeTier,
                'currency' => $inst->currency,
                'quantity' => $qty,
                'cost_per_share' => $costPerShare,
                'total_cost' => $costTotal,
                'close' => $close,
                'market_value' => $marketValue,
                'market_value_reporting' => $mvTarget,
                'total_cost_reporting' => $costTarget,
                'unrealized_pnl' => $unrealizedPnl,
                'unrealized_pnl_pct' => $unrealizedPct,
                'unrealized_pnl_reporting' => $unrlTarget,
                'safety_category' => $safety?->risk_category ?? 'insufficient_data',
                'safety_risk_score' => $safety?->risk_score !== null ? (string) $safety->risk_score : null,
                'safeguard_triggered' => (bool) ($safety?->safeguard_triggered ?? false),
                'is_demo' => (bool) ($h->is_demo || ($quote?->is_demo ?? false)),
            ];

            if (! isset($consolidatedMap[$inst->id])) {
                $consolidatedMap[$inst->id] = [
                    'instrument_id' => $inst->id,
                    'symbol' => $inst->symbol,
                    'name' => $inst->name,
                    'exchange_code' => $inst->exchange->code,
                    'sector' => $inst->sector,
                    'industry' => $inst->industry,
                    'country_code' => $inst->country_code,
                    'market_cap_idr' => $mcapIdr,
                    'size_tier' => $sizeTier,
                    'currency' => $inst->currency,
                    'quantity' => '0.000000',
                    'total_cost' => '0.0000',
                    'close' => $close,
                    'market_value' => '0.0000',
                    'market_value_reporting' => '0.0000',
                    'total_cost_reporting' => '0.0000',
                    'unrealized_pnl' => '0.0000',
                    'unrealized_pnl_reporting' => '0.0000',
                    'accounts_count' => 0,
                    'safety_category' => $safety?->risk_category ?? 'insufficient_data',
                    'safety_risk_score' => $safety?->risk_score !== null ? (string) $safety->risk_score : null,
                    'safeguard_triggered' => (bool) ($safety?->safeguard_triggered ?? false),
                    'is_demo' => (bool) ($h->is_demo || ($quote?->is_demo ?? false)),
                ];
            }

            $c = &$consolidatedMap[$inst->id];
            $c['quantity'] = Dec::add($c['quantity'], $qty, 6);
            $c['total_cost'] = Dec::add($c['total_cost'], $costTotal, 4);
            $c['accounts_count']++;
            if ($marketValue !== null) {
                $c['market_value'] = Dec::add($c['market_value'], $marketValue, 4);
            }
            if ($mvTarget !== null) {
                $c['market_value_reporting'] = Dec::add($c['market_value_reporting'], $mvTarget, 4);
            }
            if ($costTarget !== null) {
                $c['total_cost_reporting'] = Dec::add($c['total_cost_reporting'], $costTarget, 4);
            }
            if ($unrealizedPnl !== null) {
                $c['unrealized_pnl'] = Dec::add($c['unrealized_pnl'], $unrealizedPnl, 4);
            }
            if ($unrlTarget !== null) {
                $c['unrealized_pnl_reporting'] = Dec::add($c['unrealized_pnl_reporting'], $unrlTarget, 4);
            }
            unset($c);
        }

        $totalEquityTarget = Dec::add($totalMarketValueTarget, $totalCashTarget, 4);
        $totalUnrealizedTarget = Dec::sub($totalMarketValueTarget, $totalCostTarget, 4);
        $totalUnrealizedPct = Dec::cmp($totalCostTarget, '0') > 0
            ? Dec::div($totalUnrealizedTarget, $totalCostTarget, 8)
            : null;
        $totalPnlTarget = Dec::add(Dec::add($totalRealizedTarget, $totalUnrealizedTarget, 4), $totalDividendsTarget, 4);
        $returnOnContribPct = Dec::cmp($totalNetContribTarget, '0') > 0
            ? Dec::div($totalPnlTarget, $totalNetContribTarget, 8)
            : null;

        $consolidatedHoldings = [];
        foreach ($consolidatedMap as $row) {
            $q = $row['quantity'];
            $row['avg_cost_per_share'] = Dec::cmp($q, '0') > 0 ? (Dec::div($row['total_cost'], $q, 6) ?? '0.000000') : '0.000000';
            $row['unrealized_pnl_pct'] = Dec::cmp($row['total_cost'], '0') > 0
                ? Dec::div($row['unrealized_pnl'], $row['total_cost'], 8)
                : null;
            $row['weight_pct'] = Dec::cmp($totalEquityTarget, '0') > 0
                ? Dec::div($row['market_value_reporting'], $totalEquityTarget, 8)
                : '0.00000000';
            $consolidatedHoldings[] = $row;
        }

        usort($consolidatedHoldings, fn ($a, $b) => Dec::cmp($b['market_value_reporting'], $a['market_value_reporting']));

        $prevMarketValueTarget = Dec::sub($totalMarketValueTarget, $totalDailyChangeTarget, 4);
        $dailyChangePct = Dec::cmp($prevMarketValueTarget, '0') > 0
            ? Dec::div($totalDailyChangeTarget, $prevMarketValueTarget, 8)
            : null;
        $riskStatus = match (true) {
            count($consolidatedHoldings) === 0 => 'empty',
            count($safetyFlaggedIds) > 0 => 'attention_required',
            default => 'balanced',
        };

        return [
            'portfolio' => [
                'id' => $portfolio->id,
                'name' => $portfolio->name,
                'reporting_currency' => $targetCcy,
                'cost_basis_method' => $method,
            ],
            'summary' => [
                'reporting_currency' => $targetCcy,
                'cost_basis_method' => $method,
                'market_value' => $totalMarketValueTarget,
                'cash_balance' => $totalCashTarget,
                'total_equity' => $totalEquityTarget,
                'invested_cost' => $totalCostTarget,
                'net_contributions' => $totalNetContribTarget,
                'unrealized_pnl' => $totalUnrealizedTarget,
                'unrealized_pnl_pct' => $totalUnrealizedPct,
                'realized_pnl' => $totalRealizedTarget,
                'dividends_received' => $totalDividendsTarget,
                'fees_paid' => $totalFeesTarget,
                'total_pnl' => $totalPnlTarget,
                'return_on_contributions_pct' => $returnOnContribPct,
                'daily_change' => $totalDailyChangeTarget,
                'daily_change_pct' => $dailyChangePct,
                'holdings_count' => count($consolidatedHoldings),
                'accounts_count' => $filteredAccounts->count(),
                'safety_flagged_count' => count($safetyFlaggedIds),
                'risk_status' => $riskStatus,
                'as_of' => $latestQuoteDate,
                'last_synced_at' => $latestSyncedAt,
                'is_demo' => $anyDemo,
                'missing_fx' => array_values(array_unique($missingFx)),
            ],
            'accounts' => $serializedAccounts,
            'accountComparison' => $accountComparison,
            'consolidatedHoldings' => $consolidatedHoldings,
            'accountHoldings' => $accountHoldings,
        ];
    }

    /**
     * Aggregated holding status for a single instrument (used by Stock Detail contextual panel).
     *
     * @return array<string, mixed>|null
     */
    public function holdingStatusForInstrument(User $user, Instrument $instrument): ?array
    {
        $portfolio = $user->portfolios()->where('is_default', true)->first();
        if (! $portfolio) {
            return null;
        }

        $method = $portfolio->cost_basis_method ?: 'weighted_average';
        $holdings = PortfolioHolding::query()
            ->with('account:id,account_name,base_currency')
            ->where('user_id', $user->id)
            ->where('portfolio_id', $portfolio->id)
            ->where('instrument_id', $instrument->id)
            ->get();

        if ($holdings->isEmpty()) {
            return null;
        }

        $totalQty = '0.000000';
        $totalCost = '0.0000';
        $accounts = [];

        foreach ($holdings as $h) {
            $cost = $method === 'fifo' ? (string) $h->total_cost_fifo : (string) $h->total_cost_avg;
            $totalQty = Dec::add($totalQty, (string) $h->quantity, 6);
            $totalCost = Dec::add($totalCost, $cost, 4);
            $accounts[] = [
                'account_id' => $h->brokerage_account_id,
                'account_name' => $h->account?->account_name ?? '',
                'quantity' => (string) $h->quantity,
                'cost_per_share' => $method === 'fifo' ? (string) $h->fifo_cost_per_share : (string) $h->avg_cost_per_share,
            ];
        }

        if (Dec::cmp($totalQty, '0') <= 0) {
            return null;
        }

        $avgCost = Dec::div($totalCost, $totalQty, 6) ?? '0.000000';
        $close = $instrument->latestQuote ? (string) $instrument->latestQuote->close : null;
        $marketValue = $close !== null ? Dec::mul($totalQty, $close, 4) : null;
        $unrealizedPnl = $marketValue !== null ? Dec::sub($marketValue, $totalCost, 4) : null;
        $unrealizedPct = ($unrealizedPnl !== null && Dec::cmp($totalCost, '0') > 0)
            ? Dec::div($unrealizedPnl, $totalCost, 8)
            : null;

        return [
            'cost_basis_method' => $method,
            'currency' => $instrument->currency,
            'quantity' => $totalQty,
            'avg_cost_per_share' => $avgCost,
            'total_cost' => $totalCost,
            'market_value' => $marketValue,
            'unrealized_pnl' => $unrealizedPnl,
            'unrealized_pnl_pct' => $unrealizedPct,
            'accounts' => $accounts,
        ];
    }
}
