<?php

namespace App\Http\Controllers;

use App\Domain\Performance\Services\BenchmarkComparisonService;
use App\Domain\Performance\Services\PerformanceAttributionService;
use App\Domain\Performance\Services\PortfolioNavSeriesService;
use App\Domain\Performance\Services\TwrCalculator;
use App\Domain\Performance\Services\XirrCalculator;
use App\Domain\Portfolio\Services\PortfolioValuationService;
use App\Models\BrokerageAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortfolioAnalyticsController extends Controller
{
    public function index(
        Request $request,
        PortfolioValuationService $valuation,
        PortfolioNavSeriesService $navSeries,
        TwrCalculator $twrCalculator,
        XirrCalculator $xirrCalculator,
        PerformanceAttributionService $attributionService,
        BenchmarkComparisonService $benchmarkService,
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
        $customStart = $request->query('start_date') ? (string) $request->query('start_date') : null;
        $customEnd = $request->query('end_date') ? (string) $request->query('end_date') : null;

        $benchmarkCode = strtoupper((string) $request->query('benchmark', config('nadi.defaults.benchmark', 'IHSG')));
        $benchmarkCcyMode = (string) $request->query('benchmark_ccy_mode', 'reporting_aligned');
        if (! in_array($benchmarkCcyMode, ['reporting_aligned', 'native_index'], true)) {
            $benchmarkCcyMode = 'reporting_aligned';
        }

        $summaryData = $valuation->summarize($user, $portfolio, $accountId, $reportingCurrency, $costBasisMethod);
        $windowSeries = $navSeries->buildWindowSeries(
            $user,
            $portfolio,
            $accountId,
            $reportingCurrency,
            $costBasisMethod,
            $range,
            $customStart,
            $customEnd,
        );
        $twr = $twrCalculator->calculate($windowSeries);
        $xirr = $xirrCalculator->calculateForWindow($windowSeries);
        $attribution = $attributionService->attribute($user, $portfolio, $windowSeries, $twr, $accountId);
        $benchmark = $benchmarkService->compare($windowSeries, $twr, $benchmarkCode, $benchmarkCcyMode);
        $benchmarks = $benchmarkService->listBenchmarks();

        return Inertia::render('portfolio/analytics', [
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
                'inception_date' => $windowSeries['inception_date'],
                'benchmark' => $benchmark['code'],
                'benchmark_ccy_mode' => $benchmark['currency_mode'],
            ],
            'currencies' => $currencies,
            'benchmarks' => $benchmarks,
            'windowMeta' => [
                'has_data' => $windowSeries['has_data'],
                'inception_date' => $windowSeries['inception_date'],
                'start_date' => $windowSeries['start_date'],
                'end_date' => $windowSeries['end_date'],
                'begin_equity' => $windowSeries['begin_equity'],
                'end_equity' => $windowSeries['end_equity'],
                'missing_price_symbols' => $windowSeries['missing_price_symbols'],
                'missing_fx' => $windowSeries['missing_fx'],
                'is_demo' => $windowSeries['is_demo'],
            ],
            'twr' => $twr,
            'xirr' => $xirr,
            'attribution' => $attribution,
            'benchmark' => $benchmark,
        ]);
    }
}
