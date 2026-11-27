<?php

namespace Database\Seeders;

use App\Models\DataSource;
use Illuminate\Database\Seeder;

class DataSourceSeeder extends Seeder
{
    public function run(): void
    {
        DataSource::query()->updateOrCreate(
            ['code' => 'demo'],
            [
                'name' => 'NADI Demo Market Generator',
                'type' => 'demo',
                'datasets' => ['prices_daily', 'index_values_daily', 'financial_statements', 'corporate_actions', 'fx_rates_daily'],
                'is_enabled' => (bool) config('nadi.market_data.demo.enabled', true),
                'is_demo' => true,
                'attribution' => 'Synthetic Demonstration Series (Not Real Market Data)',
                'license_notes' => 'Deterministic pseudo-random OHLCV, fundamental, and corporate action series generated locally for UI and analytics demonstration only.',
                'config' => ['generator' => 'geometric_random_walk_v1'],
            ],
        );

        DataSource::query()->updateOrCreate(
            ['code' => 'csv_manual'],
            [
                'name' => 'Manual CSV / Statement Importer',
                'type' => 'csv',
                'datasets' => ['prices_daily', 'market_holidays', 'financial_statements', 'corporate_actions'],
                'is_enabled' => true,
                'is_demo' => false,
                'attribution' => 'User-supplied market data imports',
                'license_notes' => 'Supports CSV uploads via Admin Import & Sync Manager with formula-injection sanitization.',
                'config' => ['status_note' => 'Active (Phase 2)'],
            ],
        );
    }
}
