<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ExchangeSeeder::class,
            MarketUniverseSeeder::class,
            DataSourceSeeder::class,
            FeatureFlagSeeder::class,
            ScoringConfigSeeder::class,
            SafetyConfigSeeder::class,
            BrokerSeeder::class,
            AdminUserSeeder::class,
            DemoPriceSeeder::class,
            DemoMarketIntelligenceSeeder::class,
            DemoPortfolioSeeder::class,
            DemoNewsAndResearchSeeder::class,
        ]);
    }
}
