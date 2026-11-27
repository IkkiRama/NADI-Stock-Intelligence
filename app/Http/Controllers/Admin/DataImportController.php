<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Administration\Services\AuditLogger;
use App\Domain\MarketData\Actions\RefreshMarketIntelligence;
use App\Domain\MarketData\Services\CsvMarketImporter;
use App\Http\Controllers\Controller;
use App\Models\BrokerSummaryDaily;
use App\Models\CorporateAction;
use App\Models\DataImportRun;
use App\Models\DataSource;
use App\Models\FinancialStatement;
use App\Models\InstrumentFundamentalLatest;
use App\Models\InstrumentPriceDaily;
use App\Models\InstrumentScore;
use App\Models\InstrumentTechnicalLatest;
use App\Models\MarketHoliday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DataImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/imports', [
            'runs' => DataImportRun::query()
                ->with(['dataSource:id,code,name', 'user:id,email'])
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->limit(30)
                ->get()
                ->map(fn (DataImportRun $r): array => [
                    'id' => $r->id,
                    'dataset' => $r->dataset,
                    'mode' => $r->mode,
                    'status' => $r->status,
                    'rows_processed' => $r->rows_processed,
                    'rows_inserted' => $r->rows_inserted,
                    'rows_skipped' => $r->rows_skipped,
                    'error_summary' => $r->error_summary,
                    'started_at' => $r->started_at?->toIso8601String(),
                    'finished_at' => $r->finished_at?->toIso8601String(),
                    'source' => $r->dataSource?->name ?? 'Batch',
                    'user_email' => $r->user?->email ?? 'system',
                ]),
            'coverage' => [
                'prices_daily' => InstrumentPriceDaily::query()->count(),
                'market_holidays' => MarketHoliday::query()->count(),
                'financial_statements' => FinancialStatement::query()->count(),
                'corporate_actions' => CorporateAction::query()->count(),
                'broker_summaries_daily' => BrokerSummaryDaily::query()->count(),
                'fundamentals_latest' => InstrumentFundamentalLatest::query()->count(),
                'technicals_latest' => InstrumentTechnicalLatest::query()->count(),
                'scores_latest' => InstrumentScore::query()->count(),
            ],
            'datasets' => CsvMarketImporter::DATASETS,
        ]);
    }

    public function importCsv(Request $request, CsvMarketImporter $importer, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'dataset' => ['required', 'string', Rule::in(CsvMarketImporter::DATASETS)],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $source = DataSource::query()->where('code', 'csv_manual')->firstOrFail();

        $run = $importer->importUploadedFile(
            $validated['file'],
            $validated['dataset'],
            $source,
            $request->user()->id,
        );

        $audit->log('import.csv', $run, null, [
            'dataset' => $run->dataset,
            'status' => $run->status,
            'rows_processed' => $run->rows_processed,
            'rows_inserted' => $run->rows_inserted,
            'rows_skipped' => $run->rows_skipped,
        ], $request);

        return back()->with('status', 'csv-imported');
    }

    public function refreshAnalytics(Request $request, RefreshMarketIntelligence $refresh, AuditLogger $audit): RedirectResponse
    {
        $counts = $refresh->execute($request->user()->id);

        $audit->log('analytics.refreshed', null, null, $counts, $request);

        return back()->with('status', 'analytics-refreshed');
    }
}
