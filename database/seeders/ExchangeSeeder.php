<?php

namespace Database\Seeders;

use App\Models\Exchange;
use Illuminate\Database\Seeder;

class ExchangeSeeder extends Seeder
{
    public function run(): void
    {
        $idxSessions = [
            '1' => [['09:00', '12:00'], ['13:30', '15:50']],
            '2' => [['09:00', '12:00'], ['13:30', '15:50']],
            '3' => [['09:00', '12:00'], ['13:30', '15:50']],
            '4' => [['09:00', '12:00'], ['13:30', '15:50']],
            '5' => [['09:00', '11:30'], ['14:00', '15:50']],
        ];

        $usSessions = [
            '1' => [['09:30', '16:00']],
            '2' => [['09:30', '16:00']],
            '3' => [['09:30', '16:00']],
            '4' => [['09:30', '16:00']],
            '5' => [['09:30', '16:00']],
        ];

        $sgxSessions = [
            '1' => [['09:00', '12:00'], ['13:00', '17:00']],
            '2' => [['09:00', '12:00'], ['13:00', '17:00']],
            '3' => [['09:00', '12:00'], ['13:00', '17:00']],
            '4' => [['09:00', '12:00'], ['13:00', '17:00']],
            '5' => [['09:00', '12:00'], ['13:00', '17:00']],
        ];

        $cnSessions = [
            '1' => [['09:30', '11:30'], ['13:00', '15:00']],
            '2' => [['09:30', '11:30'], ['13:00', '15:00']],
            '3' => [['09:30', '11:30'], ['13:00', '15:00']],
            '4' => [['09:30', '11:30'], ['13:00', '15:00']],
            '5' => [['09:30', '11:30'], ['13:00', '15:00']],
        ];

        $hkSessions = [
            '1' => [['09:30', '12:00'], ['13:00', '16:00']],
            '2' => [['09:30', '12:00'], ['13:00', '16:00']],
            '3' => [['09:30', '12:00'], ['13:00', '16:00']],
            '4' => [['09:30', '12:00'], ['13:00', '16:00']],
            '5' => [['09:30', '12:00'], ['13:00', '16:00']],
        ];

        $exchanges = [
            [
                'code' => 'IDX',
                'name' => 'Indonesia Stock Exchange',
                'mic' => 'XIDX',
                'country_code' => 'ID',
                'timezone' => 'Asia/Jakarta',
                'currency' => 'IDR',
                'default_lot_size' => 100,
                'sessions' => $idxSessions,
                'is_active' => true,
            ],
            [
                'code' => 'NYSE',
                'name' => 'New York Stock Exchange',
                'mic' => 'XNYS',
                'country_code' => 'US',
                'timezone' => 'America/New_York',
                'currency' => 'USD',
                'default_lot_size' => 1,
                'sessions' => $usSessions,
                'is_active' => true,
            ],
            [
                'code' => 'NASDAQ',
                'name' => 'Nasdaq Stock Market',
                'mic' => 'XNAS',
                'country_code' => 'US',
                'timezone' => 'America/New_York',
                'currency' => 'USD',
                'default_lot_size' => 1,
                'sessions' => $usSessions,
                'is_active' => true,
            ],
            [
                'code' => 'SGX',
                'name' => 'Singapore Exchange',
                'mic' => 'XSES',
                'country_code' => 'SG',
                'timezone' => 'Asia/Singapore',
                'currency' => 'SGD',
                'default_lot_size' => 100,
                'sessions' => $sgxSessions,
                'is_active' => true,
            ],
            [
                'code' => 'SSE',
                'name' => 'Shanghai Stock Exchange',
                'mic' => 'XSHG',
                'country_code' => 'CN',
                'timezone' => 'Asia/Shanghai',
                'currency' => 'CNY',
                'default_lot_size' => 100,
                'sessions' => $cnSessions,
                'is_active' => true,
            ],
            [
                'code' => 'SZSE',
                'name' => 'Shenzhen Stock Exchange',
                'mic' => 'XSHE',
                'country_code' => 'CN',
                'timezone' => 'Asia/Shanghai',
                'currency' => 'CNY',
                'default_lot_size' => 100,
                'sessions' => $cnSessions,
                'is_active' => true,
            ],
            [
                'code' => 'HKEX',
                'name' => 'Hong Kong Exchanges and Clearing',
                'mic' => 'XHKG',
                'country_code' => 'HK',
                'timezone' => 'Asia/Hong_Kong',
                'currency' => 'HKD',
                'default_lot_size' => 100,
                'sessions' => $hkSessions,
                'is_active' => true,
            ],
        ];

        foreach ($exchanges as $exchange) {
            Exchange::query()->updateOrCreate(['code' => $exchange['code']], $exchange);
        }
    }
}
