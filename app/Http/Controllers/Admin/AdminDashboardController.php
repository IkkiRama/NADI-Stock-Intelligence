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
        ]);
    }
}
