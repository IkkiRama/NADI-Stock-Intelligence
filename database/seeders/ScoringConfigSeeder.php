<?php

namespace Database\Seeders;

use App\Domain\Analytics\Services\StockScoringService;
use App\Models\ScoringConfig;
use Illuminate\Database\Seeder;

class ScoringConfigSeeder extends Seeder
{
    public function run(): void
    {
        ScoringConfig::query()->updateOrCreate(
            ['code' => 'default'],
            [
                'name' => 'NADI Standard Quantitative Weights',
                'weights' => StockScoringService::DEFAULT_WEIGHTS,
                'version' => 'v1.0',
                'is_active' => true,
            ],
        );
    }
}
