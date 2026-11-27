<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DataImportController;
use App\Http\Controllers\Admin\DataSourceController;
use App\Http\Controllers\Admin\FeatureFlagController;
use App\Http\Controllers\Admin\ScoringConfigController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HeatmapController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\PlannedModuleController;
use App\Http\Controllers\ScreenerController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StockController;
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
