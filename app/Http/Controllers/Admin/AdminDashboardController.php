<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DataSource;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use App\Models\InstrumentQuoteLatest;
use App\Models\StockIndex;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function __invoke(): Response
    {
        $latestRefresh = InstrumentQuoteLatest::query()->max('refreshed_at');

        return Inertia::render('admin/dashboard', [
            'stats' => [
                'users' => User::query()->count(),
                'instruments' => Instrument::query()->where('is_active', true)->count(),
                'indices' => StockIndex::query()->where('is_active', true)->count(),
                'daily_bars' => InstrumentPriceDaily::query()->count(),
                'audit_logs' => AuditLog::query()->count(),
            ],
            'dataSources' => DataSource::query()->orderBy('id')->get(),
            'latestRefresh' => $latestRefresh,
            'systemHealth' => [
                'version' => (string) config('nadi.version'),
                'php_version' => PHP_VERSION,
                'environment' => app()->environment(),
                'db_driver' => (string) config('database.default'),
                'session_driver' => (string) config('session.driver'),
                'cache_driver' => (string) config('cache.default'),
                'queue_driver' => (string) config('queue.default'),
                'demo_mode' => (bool) config('nadi.market_data.demo.enabled'),
                'csp_enabled' => true,
                'vite_manifest_present' => is_file(public_path('build/manifest.json')),
            ],
        ]);
    }
}
