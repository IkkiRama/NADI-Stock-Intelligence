<?php

namespace App\Http\Controllers;

use App\Domain\Performance\Services\BenchmarkComparisonService;
use App\Domain\Reports\Services\PortfolioReportBuilderService;
use App\Domain\Reports\Services\ReportCsvExporter;
use App\Models\BrokerageAccount;
use App\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function index(
        Request $request,
        PortfolioReportBuilderService $builder,
        BenchmarkComparisonService $benchmarkService,
    ): InertiaResponse {
        $user = $request->user();
        $portfolio = $user->ensureDefaultPortfolio();
        [$type, $options] = $this->extractOptions($request, $portfolio->reporting_currency ?: 'IDR', $portfolio->cost_basis_method ?: 'weighted_average');

        $report = $builder->build($user, $portfolio, $type, $options);

        $accounts = BrokerageAccount::query()
            ->where('user_id', $user->id)
            ->where('portfolio_id', $portfolio->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'account_name', 'base_currency']);

        $history = ReportExport::query()
            ->where('user_id', $user->id)
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(fn (ReportExport $r): array => [
                'id' => $r->id,
                'report_type' => $r->report_type,
                'format' => $r->format,
                'reporting_currency' => $r->reporting_currency,
                'cost_basis_method' => $r->cost_basis_method,
                'range_code' => $r->range_code,
                'row_count' => (int) $r->row_count,
                'is_demo' => (bool) $r->is_demo,
                'generated_at' => $r->generated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('reports/index', [
            'report' => $report,
            'reportTypes' => PortfolioReportBuilderService::REPORT_TYPES,
            'ranges' => PortfolioReportBuilderService::VALID_RANGES,
            'currencies' => config('nadi.currencies', ['IDR', 'USD', 'SGD', 'CNY', 'HKD']),
            'benchmarks' => $benchmarkService->listBenchmarks(),
            'accounts' => $accounts,
            'filters' => [
                'type' => $report['report_type'],
                'account_id' => $report['meta']['account_id'],
                'currency' => $report['meta']['reporting_currency'],
                'method' => $report['meta']['cost_basis_method'],
                'range' => $report['meta']['range'],
                'benchmark' => $report['meta']['benchmark'],
                'benchmark_ccy_mode' => $report['meta']['benchmark_ccy_mode'],
            ],
            'exportHistory' => $history,
        ]);
    }

    public function printView(Request $request, PortfolioReportBuilderService $builder): View
    {
        $user = $request->user();
        $portfolio = $user->ensureDefaultPortfolio();
        [$type, $options] = $this->extractOptions($request, $portfolio->reporting_currency ?: 'IDR', $portfolio->cost_basis_method ?: 'weighted_average');

        $report = $builder->build($user, $portfolio, $type, $options);
        $this->logExport($user->id, $portfolio->id, $report, 'print');

        return view('reports.print', [
            'report' => $report,
            'locale' => $user->settings?->locale ?? config('nadi.defaults.locale', 'id'),
        ]);
    }

    public function exportCsv(
        Request $request,
        PortfolioReportBuilderService $builder,
        ReportCsvExporter $exporter,
    ): StreamedResponse {
        $user = $request->user();
        $portfolio = $user->ensureDefaultPortfolio();
        [$type, $options] = $this->extractOptions($request, $portfolio->reporting_currency ?: 'IDR', $portfolio->cost_basis_method ?: 'weighted_average');

        $report = $builder->build($user, $portfolio, $type, $options);
        $this->logExport($user->id, $portfolio->id, $report, 'csv');

        $filename = 'nadi-'.$report['report_type'].'-'.now('UTC')->format('Ymd-His').'.csv';

        return $exporter->streamResponse($report, $filename);
    }

    public function exportJson(Request $request, PortfolioReportBuilderService $builder): HttpResponse
    {
        $user = $request->user();
        $portfolio = $user->ensureDefaultPortfolio();
        [$type, $options] = $this->extractOptions($request, $portfolio->reporting_currency ?: 'IDR', $portfolio->cost_basis_method ?: 'weighted_average');

        $report = $builder->build($user, $portfolio, $type, $options);
        $this->logExport($user->id, $portfolio->id, $report, 'json');

        $filename = 'nadi-'.$report['report_type'].'-'.now('UTC')->format('Ymd-His').'.json';
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';

        return response($json, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function extractOptions(Request $request, string $defaultCcy, string $defaultMethod): array
    {
        $type = (string) $request->query('type', 'executive_statement');

        return [
            $type,
            [
                'account_id' => $request->query('account_id'),
                'currency' => $request->query('currency', $defaultCcy),
                'method' => $request->query('method', $defaultMethod),
                'range' => $request->query('range', '1Y'),
                'benchmark' => $request->query('benchmark', config('nadi.defaults.benchmark', 'IHSG')),
                'benchmark_ccy_mode' => $request->query('benchmark_ccy_mode', 'reporting_aligned'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function logExport(int $userId, int $portfolioId, array $report, string $format): void
    {
        $meta = $report['meta'] ?? [];

        ReportExport::query()->create([
            'user_id' => $userId,
            'portfolio_id' => $portfolioId,
            'report_type' => (string) ($report['report_type'] ?? 'executive_statement'),
            'format' => $format,
            'reporting_currency' => (string) ($meta['reporting_currency'] ?? 'IDR'),
            'cost_basis_method' => (string) ($meta['cost_basis_method'] ?? 'weighted_average'),
            'range_code' => (string) ($meta['range'] ?? '1Y'),
            'parameters' => [
                'account_id' => $meta['account_id'] ?? null,
                'benchmark' => $meta['benchmark'] ?? null,
                'benchmark_ccy_mode' => $meta['benchmark_ccy_mode'] ?? null,
            ],
            'row_count' => (int) ($report['row_count'] ?? 0),
            'is_demo' => (bool) ($meta['is_demo'] ?? false),
            'generated_at' => now('UTC'),
        ]);
    }
}
