<?php

namespace Database\Seeders;

use App\Models\SafetyConfig;
use Illuminate\Database\Seeder;

class SafetyConfigSeeder extends Seeder
{
    public function run(): void
    {
        SafetyConfig::query()->updateOrCreate(
            ['code' => 'default'],
            [
                'name' => 'Standar Stock Safety NADI',
                'thresholds' => SafetyConfig::DEFAULT_THRESHOLDS,
                'version' => 'v1.0',
                'is_active' => true,
            ]
        );
    }
}
