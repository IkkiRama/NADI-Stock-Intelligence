<?php

namespace Database\Seeders;

use App\Models\Broker;
use Illuminate\Database\Seeder;

class BrokerSeeder extends Seeder
{
    public function run(): void
    {
        $brokers = [
            ['code' => 'STOCKBIT', 'name' => 'Stockbit Sekuritas Digital', 'exchange_member_code' => 'XL', 'country_code' => 'ID', 'default_currency' => 'IDR', 'csv_adapter' => 'stockbit'],
            ['code' => 'AJAIB', 'name' => 'Ajaib Sekuritas Asia', 'exchange_member_code' => 'XC', 'country_code' => 'ID', 'default_currency' => 'IDR', 'csv_adapter' => 'ajaib'],
            ['code' => 'IPOT', 'name' => 'Indo Premier Sekuritas (IPOT)', 'exchange_member_code' => 'PD', 'country_code' => 'ID', 'default_currency' => 'IDR', 'csv_adapter' => 'ipot'],
            ['code' => 'BIONS', 'name' => 'BNI Sekuritas (BIONS)', 'exchange_member_code' => 'NI', 'country_code' => 'ID', 'default_currency' => 'IDR', 'csv_adapter' => 'bions'],
            ['code' => 'MOST', 'name' => 'Mandiri Sekuritas (MOST)', 'exchange_member_code' => 'CC', 'country_code' => 'ID', 'default_currency' => 'IDR', 'csv_adapter' => 'most'],
            ['code' => 'IBKR', 'name' => 'Interactive Brokers', 'exchange_member_code' => null, 'country_code' => 'US', 'default_currency' => 'USD', 'csv_adapter' => 'nadi_standard'],
            ['code' => 'POEMS', 'name' => 'Phillip Securities (POEMS)', 'exchange_member_code' => null, 'country_code' => 'SG', 'default_currency' => 'SGD', 'csv_adapter' => 'nadi_standard'],
            ['code' => 'MOOMOO', 'name' => 'Moomoo Financial', 'exchange_member_code' => null, 'country_code' => 'US', 'default_currency' => 'USD', 'csv_adapter' => 'nadi_standard'],
            ['code' => 'MANUAL', 'name' => 'Akun Manual / Kustodian Lain', 'exchange_member_code' => null, 'country_code' => 'ID', 'default_currency' => 'IDR', 'csv_adapter' => 'nadi_standard'],
        ];

        foreach ($brokers as $b) {
            Broker::query()->updateOrCreate(
                ['code' => $b['code']],
                array_merge($b, ['supports_live_sync' => false, 'is_active' => true])
            );
        }
    }
}
