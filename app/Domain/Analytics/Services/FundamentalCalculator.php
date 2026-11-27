<?php

namespace App\Domain\Analytics\Services;

use App\Domain\MarketData\Services\FxConversionService;
use App\Models\CorporateAction;
use App\Models\FinancialStatement;
use App\Models\Instrument;
use App\Support\Math\Dec;
use Carbon\CarbonImmutable;

final class FundamentalCalculator
{
    public function __construct(
        private readonly FxConversionService $fx,
    ) {}

    /**
     * Calculate point-in-time fundamental ratios for an instrument as of a trading date.
     *
     * @return array<string, mixed>|null
     */
    public function calculateForInstrument(Instrument $instrument, ?string $closePrice, string $asOfDate): ?array
    {
        $stmt = FinancialStatement::query()
            ->where('instrument_id', $instrument->id)
            ->whereDate('published_at', '<=', $asOfDate)
            ->orderByDesc('period_end_date')
            ->orderByDesc('id')
            ->first();

        if (! $stmt) {
            return null;
        }

        $prior = FinancialStatement::query()
            ->where('instrument_id', $instrument->id)
            ->where('period_type', $stmt->period_type)
            ->where('fiscal_year', $stmt->fiscal_year - 1)
            ->whereDate('published_at', '<=', $asOfDate)
            ->orderByDesc('id')
            ->first();

        $missing = [];
        $isFinancial = $instrument->sector === 'Financials' || (bool) $stmt->is_financial_sector;
        $stmtCcy = strtoupper($stmt->currency);
        $instCcy = strtoupper($instrument->currency);
        $stmtToInstFx = $this->fx->rate($stmtCcy, $instCcy, $asOfDate);

        if ($stmtToInstFx === null) {
            $missing[] = 'missing_fx_rate';
        }

        $adjNet = $this->adjustedNetIncome($stmt);
        $priorAdjNet = $prior ? $this->adjustedNetIncome($prior) : null;

        $shares = $stmt->shares_outstanding !== null ? (string) $stmt->shares_outstanding : null;
        $hasShares = $shares !== null && Dec::cmp($shares, '0') > 0;
        if (! $hasShares) {
            $missing[] = 'shares_outstanding';
        }

        $hasClose = $closePrice !== null && Dec::cmp($closePrice, '0') > 0;

        $marketCap = ($hasClose && $hasShares) ? Dec::mul($closePrice, $shares, 4) : null;
        $marketCapIdr = $marketCap !== null ? $this->fx->convert($marketCap, $instCcy, 'IDR', $asOfDate, 4) : null;

        // Per-share & valuation ratios require statement values in instrument currency
        $eps = null;
        $per = null;
        $pbv = null;

        if ($adjNet !== null && $hasShares && $stmtToInstFx !== null) {
            $adjNetInst = Dec::mul($adjNet, $stmtToInstFx, 4);
            $eps = Dec::div($adjNetInst, $shares, 6);

            if ($hasClose && $eps !== null) {
                if (Dec::cmp($eps, '0') > 0) {
                    $per = Dec::div($closePrice, $eps, 6);
                } else {
                    $missing[] = 'negative_or_zero_earnings';
                }
            }
        } else {
            if ($adjNet === null) {
                $missing[] = 'net_income';
            }
        }

        if ($stmt->total_equity !== null && Dec::cmp((string) $stmt->total_equity, '0') > 0 && $hasShares && $stmtToInstFx !== null && $hasClose) {
            $equityInst = Dec::mul((string) $stmt->total_equity, $stmtToInstFx, 4);
            $bvps = Dec::div($equityInst, $shares, 6);
            if ($bvps !== null && Dec::cmp($bvps, '0') > 0) {
                $pbv = Dec::div($closePrice, $bvps, 6);
            }
        } elseif ($stmt->total_equity === null) {
            $missing[] = 'total_equity';
        }

        // Same-currency profitability ratios
        $roe = ($adjNet !== null && $stmt->total_equity !== null && Dec::cmp((string) $stmt->total_equity, '0') > 0)
            ? Dec::div($adjNet, (string) $stmt->total_equity, 8)
            : null;

        $roa = ($adjNet !== null && $stmt->total_assets !== null && Dec::cmp((string) $stmt->total_assets, '0') > 0)
            ? Dec::div($adjNet, (string) $stmt->total_assets, 8)
            : null;

        $netMargin = ($adjNet !== null && $stmt->revenue !== null && Dec::cmp((string) $stmt->revenue, '0') > 0)
            ? Dec::div($adjNet, (string) $stmt->revenue, 8)
            : null;

        // Sector-aware leverage, liquidity & cash flows
        if ($isFinancial) {
            $der = ($stmt->total_liabilities !== null && $stmt->total_equity !== null && Dec::cmp((string) $stmt->total_equity, '0') > 0)
                ? Dec::div((string) $stmt->total_liabilities, (string) $stmt->total_equity, 6)
                : null;
            $currentRatio = null;
            $fcf = null;
        } else {
            $der = ($stmt->total_debt !== null && $stmt->total_equity !== null && Dec::cmp((string) $stmt->total_equity, '0') > 0)
                ? Dec::div((string) $stmt->total_debt, (string) $stmt->total_equity, 6)
                : null;

            $currentRatio = ($stmt->current_assets !== null && $stmt->current_liabilities !== null && Dec::cmp((string) $stmt->current_liabilities, '0') > 0)
                ? Dec::div((string) $stmt->current_assets, (string) $stmt->current_liabilities, 6)
                : null;

            if ($stmt->free_cash_flow !== null) {
                $fcf = Dec::round((string) $stmt->free_cash_flow, 4);
            } elseif ($stmt->operating_cash_flow !== null && $stmt->capex !== null) {
                $capexAbs = Dec::cmp((string) $stmt->capex, '0') < 0
                    ? Dec::mul((string) $stmt->capex, '-1', 4)
                    : (string) $stmt->capex;
                $fcf = Dec::sub((string) $stmt->operating_cash_flow, $capexAbs, 4);
            } else {
                $fcf = null;
            }
        }

        $ocf = $stmt->operating_cash_flow !== null ? Dec::round((string) $stmt->operating_cash_flow, 4) : null;

        // YoY Growth (comparing same statement currency; guard negative prior base)
        $revGrowth = ($stmt->revenue !== null && $prior?->revenue !== null && Dec::cmp((string) $prior->revenue, '0') > 0)
            ? Dec::pctChange((string) $stmt->revenue, (string) $prior->revenue, 8)
            : null;

        $earningsGrowth = ($adjNet !== null && $priorAdjNet !== null && Dec::cmp($priorAdjNet, '0') > 0)
            ? Dec::pctChange($adjNet, $priorAdjNet, 8)
            : null;

        // Dividend yield (trailing 365d cash_dividend actions, fallback to dividends_paid / shares)
        $dividendYield = null;
        if ($hasClose) {
            $windowStart = CarbonImmutable::parse($asOfDate, 'UTC')->subDays(365)->toDateString();
            $divActions = CorporateAction::query()
                ->where('instrument_id', $instrument->id)
                ->where('action_type', 'cash_dividend')
                ->whereDate('ex_date', '>=', $windowStart)
                ->whereDate('ex_date', '<=', $asOfDate)
                ->get();

            if ($divActions->isNotEmpty()) {
                $dpsTotal = '0';
                $allConverted = true;
                foreach ($divActions as $act) {
                    if ($act->cash_amount === null) {
                        continue;
                    }
                    $actCcy = strtoupper((string) ($act->declared_currency ?: $instCcy));
                    $converted = $this->fx->convert((string) $act->cash_amount, $actCcy, $instCcy, $act->ex_date->toDateString(), 6);
                    if ($converted === null) {
                        $allConverted = false;
                        break;
                    }
                    $dpsTotal = Dec::add($dpsTotal, $converted, 6);
                }
                if ($allConverted && Dec::cmp($dpsTotal, '0') > 0) {
                    $dividendYield = Dec::div($dpsTotal, $closePrice, 8);
                }
            } elseif ($stmt->dividends_paid !== null && $hasShares && $stmtToInstFx !== null) {
                $divRaw = Dec::cmp((string) $stmt->dividends_paid, '0') < 0
                    ? Dec::mul((string) $stmt->dividends_paid, '-1', 4)
                    : (string) $stmt->dividends_paid;
                if (Dec::cmp($divRaw, '0') > 0) {
                    $divInst = Dec::mul($divRaw, $stmtToInstFx, 4);
                    $dps = Dec::div($divInst, $shares, 6);
                    if ($dps !== null) {
                        $dividendYield = Dec::div($dps, $closePrice, 8);
                    }
                }
            }
        }

        $dividendPayout = null;
        if ($stmt->dividends_paid !== null && $adjNet !== null && Dec::cmp($adjNet, '0') > 0) {
            $divRaw = Dec::cmp((string) $stmt->dividends_paid, '0') < 0
                ? Dec::mul((string) $stmt->dividends_paid, '-1', 4)
                : (string) $stmt->dividends_paid;
            $dividendPayout = Dec::div($divRaw, $adjNet, 8);
        }

        return [
            'instrument_id' => $instrument->id,
            'as_of_date' => $asOfDate,
            'period_label' => "{$stmt->period_type} {$stmt->fiscal_year}",
            'statement_currency' => $stmtCcy,
            'market_cap' => $marketCap,
            'market_cap_idr' => $marketCapIdr,
            'eps' => $eps,
            'per' => $per,
            'pbv' => $pbv,
            'roe' => $roe,
            'roa' => $roa,
            'der' => $der,
            'current_ratio' => $currentRatio,
            'net_margin' => $netMargin,
            'revenue_growth_yoy' => $revGrowth,
            'earnings_growth_yoy' => $earningsGrowth,
            'ocf' => $ocf,
            'fcf' => $fcf,
            'dividend_yield' => $dividendYield,
            'dividend_payout_ratio' => $dividendPayout,
            'is_financial_sector' => $isFinancial,
            'missing_fields' => array_values(array_unique($missing)),
            'data_source_id' => $stmt->data_source_id,
            'is_demo' => (bool) $stmt->is_demo,
            'calculated_at' => now('UTC'),
        ];
    }

    private function adjustedNetIncome(FinancialStatement $stmt): ?string
    {
        if ($stmt->net_income === null) {
            return null;
        }

        $extra = $stmt->extraordinary_items !== null ? (string) $stmt->extraordinary_items : '0';

        return Dec::sub((string) $stmt->net_income, $extra, 4);
    }
}
