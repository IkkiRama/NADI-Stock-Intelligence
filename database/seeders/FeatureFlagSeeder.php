<?php

namespace Database\Seeders;

use App\Models\FeatureFlag;
use Illuminate\Database\Seeder;

class FeatureFlagSeeder extends Seeder
{
    public function run(): void
    {
        $flags = [
            [
                'key' => 'dashboard_customization',
                'enabled' => true,
                'description' => 'Allow users to customize widget visibility and ordering on the Overview dashboard.',
            ],
            [
                'key' => 'pwa',
                'enabled' => true,
                'description' => 'Enable Progressive Web App manifest and static build-asset caching service worker.',
            ],
        ];

        foreach ($flags as $flag) {
            FeatureFlag::query()->updateOrCreate(['key' => $flag['key']], $flag);
        }
    }
}
