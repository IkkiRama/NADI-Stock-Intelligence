<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DataImportController;
use App\Http\Controllers\Admin\DataSourceController;
use App\Http\Controllers\Admin\FeatureFlagController;
use App\Http\Controllers\Admin\SafetyRuleController;
use App\Http\Controllers\Admin\ScoringConfigController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\AlertsCalendarController;
use App\Http\Controllers\BrokerIntelligenceController;
use App\Http\Controllers\CalculatorsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DividendsController;
use App\Http\Controllers\EntryExitController;
use App\Http\Controllers\HeatmapController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\NewsroomController;
use App\Http\Controllers\OpportunitiesController;
use App\Http\Controllers\PlannedModuleController;
use App\Http\Controllers\PortfolioAnalyticsController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ResearchCenterController;
use App\Http\Controllers\RiskRebalancingController;
use App\Http\Controllers\ScreenerController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockSafetyController;
use App\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::patch('dashboard/layout', [DashboardController::class, 'updateLayout'])->name('dashboard.layout');

    Route::get('markets', [MarketController::class, 'index'])->name('markets.index');
    Route::get('markets/indices/{code}', [MarketController::class, 'showIndex'])->name('markets.indices.show');
    Route::get('heatmap', [HeatmapController::class, 'index'])->name('heatmap.index');

    Route::get('stocks', [StockController::class, 'index'])->name('stocks.index');
    Route::get('stocks/{exchange}/{symbol}', [StockController::class, 'show'])->name('stocks.show');

    Route::get('screener', [ScreenerController::class, 'index'])->name('screener.index');
    Route::get('screener/export', [ScreenerController::class, 'export'])->name('screener.export');
    Route::post('screener/presets', [ScreenerController::class, 'storePreset'])->name('screener.presets.store');
    Route::delete('screener/presets/{savedScreener}', [ScreenerController::class, 'destroyPreset'])->name('screener.presets.destroy');

    Route::get('opportunities', [OpportunitiesController::class, 'index'])->name('opportunities.index');
    Route::get('stock-safety', [StockSafetyController::class, 'index'])->name('stock-safety.index');
    Route::get('entry-exit', [EntryExitController::class, 'index'])->name('entry-exit.index');
    Route::post('entry-exit/position-size', [EntryExitController::class, 'calculatePositionSize'])->name('entry-exit.position-size');

    Route::get('alerts-calendar', [AlertsCalendarController::class, 'index'])->name('alerts-calendar.index');
    Route::post('alerts', [AlertsCalendarController::class, 'store'])->name('alerts.store');
    Route::patch('alerts/{userAlert}/toggle', [AlertsCalendarController::class, 'toggle'])->name('alerts.toggle');
    Route::delete('alerts/{userAlert}', [AlertsCalendarController::class, 'destroy'])->name('alerts.destroy');
    Route::post('alerts/events/{alertEvent}/read', [AlertsCalendarController::class, 'markEventRead'])->name('alerts.events.read');

    Route::get('portfolio', [PortfolioController::class, 'index'])->name('portfolio.index');
    Route::get('portfolio-analytics', [PortfolioAnalyticsController::class, 'index'])->name('portfolio.analytics');
    Route::get('risk-rebalancing', [RiskRebalancingController::class, 'index'])->name('portfolio.risk-rebalancing');
    Route::put('risk-rebalancing/targets', [RiskRebalancingController::class, 'updateTargets'])->name('portfolio.risk-rebalancing.targets');
    Route::post('risk-rebalancing/presets', [RiskRebalancingController::class, 'applyPreset'])->name('portfolio.risk-rebalancing.presets');

    Route::get('dividends', [DividendsController::class, 'index'])->name('dividends.index');
    Route::get('newsroom', [NewsroomController::class, 'index'])->name('newsroom.index');
    Route::post('newsroom/articles', [NewsroomController::class, 'store'])->name('newsroom.articles.store');

    Route::get('research-center', [ResearchCenterController::class, 'index'])->name('research.index');
    Route::post('research-center/notes', [ResearchCenterController::class, 'store'])->name('research.notes.store');
    Route::put('research-center/notes/{researchNote}', [ResearchCenterController::class, 'update'])->name('research.notes.update');
    Route::patch('research-center/notes/{researchNote}/pin', [ResearchCenterController::class, 'togglePin'])->name('research.notes.pin');
    Route::delete('research-center/notes/{researchNote}', [ResearchCenterController::class, 'destroy'])->name('research.notes.destroy');

    Route::get('reports', [ReportsController::class, 'index'])->name('reports.index');
    Route::get('reports/print', [ReportsController::class, 'printView'])->name('reports.print');
    Route::get('reports/export-csv', [ReportsController::class, 'exportCsv'])->name('reports.export-csv');
    Route::get('reports/export-json', [ReportsController::class, 'exportJson'])->name('reports.export-json');

    Route::get('broker-intelligence', [BrokerIntelligenceController::class, 'index'])->name('broker-intelligence.index');
    Route::get('broker-intelligence/export-csv', [BrokerIntelligenceController::class, 'exportCsv'])->name('broker-intelligence.export-csv');
    Route::post('portfolio/accounts', [PortfolioController::class, 'storeAccount'])->name('portfolio.accounts.store');
    Route::patch('portfolio/preferences', [PortfolioController::class, 'updatePreferences'])->name('portfolio.preferences.update');
    Route::patch('portfolio/concentration-thresholds', [PortfolioController::class, 'updateConcentrationThresholds'])->name('portfolio.thresholds.update');
    Route::post('portfolio/transactions', [PortfolioController::class, 'storeTransaction'])->name('portfolio.transactions.store');
    Route::post('portfolio/transactions/{portfolioTransaction}/reverse', [PortfolioController::class, 'reverseTransaction'])->name('portfolio.transactions.reverse');
    Route::post('portfolio/import-csv', [PortfolioController::class, 'importCsv'])->name('portfolio.import-csv');
    Route::get('portfolio/transactions/export', [PortfolioController::class, 'exportTransactions'])->name('portfolio.transactions.export');
    Route::post('portfolio/reconcile', [PortfolioController::class, 'reconcile'])->name('portfolio.reconcile');

    Route::get('calculators', [CalculatorsController::class, 'index'])->name('calculators.index');

    Route::get('search', SearchController::class)->middleware('throttle:60,1')->name('search');

    Route::post('watchlist/items', [WatchlistController::class, 'store'])->name('watchlist.items.store');
    Route::delete('watchlist/items/{instrument}', [WatchlistController::class, 'destroy'])->name('watchlist.items.destroy');

    Route::get('segera/{slug}', [PlannedModuleController::class, 'show'])->name('modules.planned');

    Route::prefix('admin')
        ->name('admin.')
        ->middleware('can:access-admin')
        ->group(function () {
            Route::get('/', AdminDashboardController::class)->name('dashboard');

            Route::get('users', [UserManagementController::class, 'index'])
                ->middleware('can:manage-users')
                ->name('users.index');
            Route::patch('users/{user}', [UserManagementController::class, 'update'])
                ->middleware('can:manage-users')
                ->name('users.update');

            Route::get('data-sources', [DataSourceController::class, 'index'])
                ->middleware('can:manage-data-sources')
                ->name('data-sources.index');
            Route::patch('data-sources/{dataSource}', [DataSourceController::class, 'update'])
                ->middleware('can:manage-data-sources')
                ->name('data-sources.update');

            Route::get('imports', [DataImportController::class, 'index'])
                ->middleware('can:manage-imports')
                ->name('imports.index');
            Route::post('imports/csv', [DataImportController::class, 'importCsv'])
                ->middleware('can:manage-imports')
                ->name('imports.csv');
            Route::post('imports/refresh', [DataImportController::class, 'refreshAnalytics'])
                ->middleware('can:manage-imports')
                ->name('imports.refresh');

            Route::get('scoring', [ScoringConfigController::class, 'edit'])
                ->middleware('can:manage-scoring')
                ->name('scoring.edit');
            Route::patch('scoring', [ScoringConfigController::class, 'update'])
                ->middleware('can:manage-scoring')
                ->name('scoring.update');

            Route::get('safety-rules', [SafetyRuleController::class, 'edit'])
                ->middleware('can:manage-safety')
                ->name('safety-rules.edit');
            Route::patch('safety-rules', [SafetyRuleController::class, 'update'])
                ->middleware('can:manage-safety')
                ->name('safety-rules.update');
            Route::patch('safety-rules/instruments/{instrument}', [SafetyRuleController::class, 'updateInstrument'])
                ->middleware('can:manage-safety')
                ->name('safety-rules.instrument');

            Route::get('feature-flags', [FeatureFlagController::class, 'index'])
                ->middleware('can:manage-feature-flags')
                ->name('feature-flags.index');
            Route::patch('feature-flags/{featureFlag}', [FeatureFlagController::class, 'update'])
                ->middleware('can:manage-feature-flags')
                ->name('feature-flags.update');

            Route::get('audit-logs', [AuditLogController::class, 'index'])
                ->middleware('can:view-audit-logs')
                ->name('audit-logs.index');
        });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
