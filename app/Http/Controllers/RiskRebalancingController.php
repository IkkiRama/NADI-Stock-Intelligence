<?php

namespace App\Http\Controllers;

use App\Domain\Performance\Services\BenchmarkComparisonService;
use App\Domain\Performance\Services\PortfolioNavSeriesService;
use App\Domain\Performance\Services\TwrCalculator;
use App\Domain\Portfolio\Services\PortfolioValuationService;
use App\Domain\Risk\Services\HoldingCorrelationAndExposureService;
use App\Domain\Risk\Services\PortfolioRebalancingService;
use App\Domain\Risk\Services\PortfolioRiskAnalyticsService;
use App\Models\BrokerageAccount;
use App\Models\Instrument;
use App\Models\PortfolioRebalanceTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RiskRebalancingController extends Controller
{
    public function index(
        Request $request,
        PortfolioValuationService $valuation,
        PortfolioNavSeriesService $navSeries,
        TwrCalculator $twrCalculator,
        BenchmarkComparisonService $benchmarkService,
        PortfolioRiskAnalyticsService $riskService,
        HoldingCorrelationAndExposureService $correlationService,
        PortfolioRebalancingService $rebalancingService,
    ): Response {
        $user = $request->user();
        $portfolio = $user->ensureDefaultPortfolio();

        $accountId = $request->query('account_id') ? (int) $request->query('account_id') : null;
        if ($accountId !== null) {
            $owned = BrokerageAccount::query()
                ->where('id', $accountId)
                ->where('user_id', $user->id)
                ->exists();
            if (! $owned) {
                $accountId = null;
            }
        }

        $currencies = config('nadi.currencies', ['IDR', 'USD', 'SGD', 'CNY', 'HKD']);
        $reportingCurrency = strtoupper((string) $request->query('currency', $portfolio->reporting_currency));
        if (! in_array($reportingCurrency, $currencies, true)) {
            $reportingCurrency = $portfolio->reporting_currency ?: 'IDR';
        }

        $costBasisMethod = (string) $request->query('method', $portfolio->cost_basis_method);
        if (! in_array($costBasisMethod, ['weighted_average', 'fifo'], true)) {
            $costBasisMethod = $portfolio->cost_basis_method ?: 'weighted_average';
        }

        $range = strtoupper((string) $request->query('range', '1Y'));
        if (! in_array($range, PortfolioNavSeriesService::VALID_RANGES, true)) {
            $range = '1Y';
        }

        $benchmarkCode = strtoupper((string) $request->query('benchmark', config('nadi.defaults.benchmark', 'IHSG')));
        $prefs = $portfolio->mergedRiskRebalancePrefs();

        $defaultRfFraction = config("nadi.risk_free_rates.{$reportingCurrency}")
            ?? config('nadi.risk_free_rates.DEFAULT', '0.0450');

        $rfPctParam = $request->query('rf_pct');
        $effectiveRfPct = match (true) {
            is_numeric($rfPctParam) && (float) $rfPctParam >= 0 && (float) $rfPctParam <= 30 => (float) $rfPctParam,
            $prefs['risk_free_rate_pct'] !== null => (float) $prefs['risk_free_rate_pct'],
            default => round(((float) $defaultRfFraction) * 100.0, 2),
        };
        $rfAnnualFraction = number_format($effectiveRfPct / 100.0, 6, '.', '');

        $inflowParam = $request->query('inflow');
        $additionalInflowRep = (is_numeric($inflowParam) && (float) $inflowParam >= 0)
            ? number_format((float) $inflowParam, 4, '.', '')
            : '0.0000';

        $summaryData = $valuation->summarize($user, $portfolio, $accountId, $reportingCurrency, $costBasisMethod);
        $windowSeries = $navSeries->buildWindowSeries(
            $user,
            $portfolio,
            $accountId,
            $reportingCurrency,
            $costBasisMethod,
            $range,
        );
        $twr = $twrCalculator->calculate($windowSeries);
        $benchmarkComparison = $benchmarkService->compare($windowSeries, $twr, $benchmarkCode, 'reporting_aligned');

        $risk = $riskService->analyze(
            $windowSeries,
            $twr,
            $benchmarkComparison,
            (string) ($summaryData['summary']['total_equity'] ?? '0'),
            $rfAnnualFraction,
        );
        $exposure = $correlationService->analyze($summaryData, $windowSeries['end_date'] ?? null);
        $rebalance = $rebalancingService->simulate(
            $user,
            $portfolio,
            $summaryData,
            $prefs,
            null,
            $additionalInflowRep,
        );

        $universeInstruments = Instrument::query()
            ->with('exchange:id,code')
            ->where('is_active', true)
            ->orderBy('symbol')
            ->limit(250)
            ->get(['id', 'exchange_id', 'symbol', 'name', 'currency', 'lot_size'])
            ->map(fn (Instrument $inst): array => [
                'id' => $inst->id,
                'symbol' => $inst->symbol,
                'name' => $inst->name,
                'exchange_code' => $inst->exchange?->code ?? '',
                'currency' => $inst->currency,
                'lot_size' => (int) $inst->lot_size,
            ])
            ->all();

        return Inertia::render('portfolio/risk-rebalancing', [
            'portfolio' => $summaryData['portfolio'],
            'summary' => $summaryData['summary'],
            'accounts' => $summaryData['accounts'],
            'filters' => [
                'account_id' => $accountId,
                'currency' => $reportingCurrency,
                'method' => $costBasisMethod,
                'range' => $windowSeries['range'],
                'start_date' => $windowSeries['start_date'],
                'end_date' => $windowSeries['end_date'],
                'benchmark' => $benchmarkComparison['code'],
                'rf_pct' => number_format($effectiveRfPct, 2, '.', ''),
                'inflow' => number_format((float) $additionalInflowRep, 0, '.', ''),
            ],
            'currencies' => $currencies,
            'benchmarks' => $benchmarkService->listBenchmarks(),
            'risk' => $risk,
            'benchmarkMeta' => [
                'code' => $benchmarkComparison['code'],
                'name' => $benchmarkComparison['name'],
                'index_currency' => $benchmarkComparison['index_currency'],
                'index_type' => $benchmarkComparison['index_type'],
                'observations_count' => $benchmarkComparison['observations_count'],
                'is_demo' => $benchmarkComparison['is_demo'],
            ],
            'correlation' => $exposure['correlation'],
            'currencyExposure' => $exposure['currency_exposure'],
            'rebalance' => $rebalance,
            'universeInstruments' => $universeInstruments,
        ]);
    }

    public function updateTargets(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cash_buffer_pct' => ['required', 'numeric', 'min:0', 'max:95'],
            'drift_band_pct' => ['required', 'numeric', 'min:0', 'max:25'],
            'risk_free_rate_pct' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'exclude_safety_flagged_buys' => ['required', 'boolean'],
            'targets' => ['present', 'array', 'max:40'],
            'targets.*.instrument_id' => ['required', 'integer', 'distinct', 'exists:instruments,id'],
            'targets.*.target_weight_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $sumEquityWeights = 0.0;
        foreach ($validated['targets'] as $row) {
            $sumEquityWeights += (float) $row['target_weight_pct'];
        }

        $cashBuffer = (float) $validated['cash_buffer_pct'];
        if (($sumEquityWeights + $cashBuffer) > 100.01) {
            return back()->withErrors([
                'targets' => 'Total alokasi saham ditambah cadangan kas tidak boleh melebihi 100%.',
            ]);
        }

        $user = $request->user();
        $portfolio = $user->ensureDefaultPortfolio();

        DB::transaction(function () use ($user, $portfolio, $validated, $cashBuffer): void {
            $portfolio->forceFill([
                'risk_rebalance_prefs' => [
                    'cash_buffer_pct' => round($cashBuffer, 2),
                    'drift_band_pct' => round((float) $validated['drift_band_pct'], 2),
                    'risk_free_rate_pct' => $validated['risk_free_rate_pct'] !== null
                        ? round((float) $validated['risk_free_rate_pct'], 2)
                        : null,
                    'exclude_safety_flagged_buys' => (bool) $validated['exclude_safety_flagged_buys'],
                ],
            ])->save();

            PortfolioRebalanceTarget::query()
                ->where('user_id', $user->id)
                ->where('portfolio_id', $portfolio->id)
                ->delete();

            foreach ($validated['targets'] as $row) {
                $w = round((float) $row['target_weight_pct'], 2);
                if ($w <= 0.0) {
                    continue;
                }
                PortfolioRebalanceTarget::query()->create([
                    'user_id' => $user->id,
                    'portfolio_id' => $portfolio->id,
                    'instrument_id' => (int) $row['instrument_id'],
                    'target_weight_pct' => number_format($w, 2, '.', ''),
                ]);
            }
        });

        return back()->with('status', 'rebalance-targets-saved');
    }

    public function applyPreset(Request $request, PortfolioValuationService $valuation): RedirectResponse
    {
        $validated = $request->validate([
            'preset' => ['required', 'string', Rule::in(['equal_weight', 'current_weights', 'clear'])],
            'account_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $portfolio = $user->ensureDefaultPortfolio();
        $prefs = $portfolio->mergedRiskRebalancePrefs();
        $investablePct = max(0.0, 100.0 - (float) $prefs['cash_buffer_pct']);

        $accountId = $validated['account_id'] ?? null;
        if ($accountId !== null) {
            $owned = BrokerageAccount::query()
                ->where('id', $accountId)
                ->where('user_id', $user->id)
                ->exists();
            if (! $owned) {
                $accountId = null;
            }
        }

        $summaryData = $valuation->summarize($user, $portfolio, $accountId);
        $holdings = $summaryData['consolidatedHoldings'] ?? [];

        DB::transaction(function () use ($user, $portfolio, $validated, $holdings, $investablePct): void {
            PortfolioRebalanceTarget::query()
                ->where('user_id', $user->id)
                ->where('portfolio_id', $portfolio->id)
                ->delete();

            if ($validated['preset'] === 'clear' || empty($holdings)) {
                return;
            }

            if ($validated['preset'] === 'equal_weight') {
                $n = count($holdings);
                $base = floor(($investablePct / $n) * 100.0) / 100.0;
                $rem = round($investablePct - ($base * $n), 2);
                foreach ($holdings as $idx => $h) {
                    $w = $idx === 0 ? round($base + $rem, 2) : $base;
                    if ($w > 0.0) {
                        PortfolioRebalanceTarget::query()->create([
                            'user_id' => $user->id,
                            'portfolio_id' => $portfolio->id,
                            'instrument_id' => (int) $h['instrument_id'],
                            'target_weight_pct' => number_format($w, 2, '.', ''),
                        ]);
                    }
                }

                return;
            }

            // current_weights scaled to investablePct
            $totalMv = 0.0;
            foreach ($holdings as $h) {
                $totalMv += (float) ($h['market_value_reporting'] ?? 0);
            }
            if ($totalMv <= 0.0) {
                return;
            }

            $running = 0.0;
            foreach ($holdings as $idx => $h) {
                $mv = (float) ($h['market_value_reporting'] ?? 0);
                $w = $idx === count($holdings) - 1
                    ? max(0.0, round($investablePct - $running, 2))
                    : round(($mv / $totalMv) * $investablePct, 2);
                $running += $w;
                if ($w > 0.0) {
                    PortfolioRebalanceTarget::query()->create([
                        'user_id' => $user->id,
                        'portfolio_id' => $portfolio->id,
                        'instrument_id' => (int) $h['instrument_id'],
                        'target_weight_pct' => number_format($w, 2, '.', ''),
                    ]);
                }
            }
        });

        return back()->with('status', 'rebalance-preset-applied');
    }
}
