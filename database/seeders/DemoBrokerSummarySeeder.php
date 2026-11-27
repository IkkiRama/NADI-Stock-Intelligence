<?php

namespace Database\Seeders;

use App\Models\Broker;
use App\Models\BrokerSummaryDaily;
use App\Models\DataSource;
use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use App\Support\Math\Dec;
use Illuminate\Database\Seeder;

class DemoBrokerSummarySeeder extends Seeder
{
    public function run(): void
    {
        if (! config('nadi.market_data.demo.enabled', true)) {
            return;
        }

        if (BrokerSummaryDaily::query()->where('is_demo', true)->exists()) {
            return;
        }

        $idx = Exchange::query()->where('code', 'IDX')->first();
        if (! $idx) {
            return;
        }

        $sourceId = DataSource::query()->where('code', 'demo_synthetic')->value('id');
        $brokers = Broker::query()
            ->whereNotNull('exchange_member_code')
            ->get()
            ->keyBy(fn (Broker $b) => strtoupper((string) $b->exchange_member_code));

        // Symbol => regime bias profile
        $profiles = [
            'BBCA' => 'strong_acc',
            'BMRI' => 'strong_acc',
            'BBRI' => 'acc',
            'TLKM' => 'acc',
            'ASII' => 'neutral',
            'ICBP' => 'acc',
            'UNTR' => 'dist',
            'GOTO' => 'strong_dist',
        ];

        $instruments = Instrument::query()
            ->where('exchange_id', $idx->id)
            ->whereIn('symbol', array_keys($profiles))
            ->get()
            ->keyBy('symbol');

        if ($instruments->isEmpty()) {
            return;
        }

        $memberWeights = [
            'strong_acc' => [
                'AK' => [280, 110],
                'BK' => [240, 100],
                'ZP' => [210, 105],
                'KZ' => [170, 95],
                'CC' => [150, 120],
                'SQ' => [130, 115],
                'RX' => [110, 130],
                'PD' => [100, 160],
                'NI' => [95, 165],
                'YP' => [135, 250],
                'XL' => [115, 225],
                'XC' => [90, 250],
            ],
            'acc' => [
                'AK' => [210, 135],
                'ZP' => [195, 130],
                'BK' => [180, 130],
                'CC' => [160, 135],
                'SQ' => [145, 130],
                'KZ' => [135, 145],
                'RX' => [120, 145],
                'PD' => [125, 165],
                'NI' => [115, 155],
                'YP' => [150, 200],
                'XL' => [130, 175],
                'XC' => [110, 130],
            ],
            'neutral' => [
                'AK' => [155, 150],
                'BK' => [150, 148],
                'ZP' => [145, 142],
                'KZ' => [130, 138],
                'CC' => [140, 135],
                'SQ' => [125, 130],
                'RX' => [120, 125],
                'PD' => [130, 128],
                'NI' => [125, 122],
                'YP' => [170, 175],
                'XL' => [160, 155],
                'XC' => [140, 142],
            ],
            'dist' => [
                'YP' => [210, 140],
                'XL' => [195, 135],
                'XC' => [175, 125],
                'PD' => [150, 130],
                'NI' => [140, 135],
                'SQ' => [125, 140],
                'CC' => [120, 145],
                'RX' => [110, 150],
                'KZ' => [115, 165],
                'ZP' => [110, 185],
                'BK' => [105, 195],
                'AK' => [100, 210],
            ],
            'strong_dist' => [
                'YP' => [260, 120],
                'XL' => [235, 115],
                'XC' => [210, 100],
                'PD' => [165, 115],
                'NI' => [145, 120],
                'CC' => [120, 145],
                'SQ' => [110, 150],
                'RX' => [95, 165],
                'KZ' => [90, 185],
                'ZP' => [85, 215],
                'BK' => [80, 240],
                'AK' => [75, 260],
            ],
        ];

        $batch = [];
        $now = now('UTC');

        foreach ($instruments as $symbol => $inst) {
            $profileKey = $profiles[$symbol] ?? 'neutral';
            $weights = $memberWeights[$profileKey];

            $bars = InstrumentPriceDaily::query()
                ->where('instrument_id', $inst->id)
                ->orderByDesc('trade_date')
                ->limit(20)
                ->get(['trade_date', 'close', 'volume']);

            foreach ($bars as $dayIdx => $bar) {
                $tradeDate = $bar->trade_date->toDateString();
                $close = (float) $bar->close;
                if ($close <= 0) {
                    continue;
                }

                // Base lot scale (~2,500 lots per weight unit adjusted gently by day hash)
                $dayFactor = 0.85 + (($dayIdx * 7 + strlen($symbol)) % 5) * 0.08;
                $lotScale = $symbol === 'GOTO' ? 8000 : 1200;

                foreach ($weights as $code => [$buyW, $sellW]) {
                    $broker = $brokers->get($code);
                    $jitterB = 1 + ((($dayIdx + ord($code[0])) % 5) - 2) * 0.04;
                    $jitterS = 1 + ((($dayIdx + ord($code[1] ?? 'A')) % 5) - 2) * 0.04;

                    $buyVol = (int) max(100, round($buyW * $lotScale * $dayFactor * $jitterB / 100) * 100);
                    $sellVol = (int) max(100, round($sellW * $lotScale * $dayFactor * $jitterS / 100) * 100);
                    $netVol = $buyVol - $sellVol;

                    $avgBuy = Dec::round((string) ($close * (0.996 + (($dayIdx + ord($code[0])) % 5) * 0.0018)), 2);
                    $avgSell = Dec::round((string) ($close * (0.997 + (($dayIdx + ord($code[1] ?? 'Z')) % 5) * 0.0018)), 2);

                    $buyVal = Dec::mul((string) $buyVol, $avgBuy, 4);
                    $sellVal = Dec::mul((string) $sellVol, $avgSell, 4);
                    $netVal = Dec::sub($buyVal, $sellVal, 4);

                    $batch[] = [
                        'instrument_id' => $inst->id,
                        'broker_id' => $broker?->id,
                        'broker_code' => $code,
                        'broker_category' => $broker?->broker_category ?? 'local_retail',
                        'trade_date' => $tradeDate,
                        'buy_volume' => $buyVol,
                        'sell_volume' => $sellVol,
                        'net_volume' => $netVol,
                        'buy_value' => $buyVal,
                        'sell_value' => $sellVal,
                        'net_value' => $netVal,
                        'avg_buy_price' => $avgBuy,
                        'avg_sell_price' => $avgSell,
                        'currency' => $inst->currency ?: 'IDR',
                        'data_source_id' => $sourceId,
                        'is_demo' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($batch) >= 250) {
                        $this->flush($batch);
                        $batch = [];
                    }
                }
            }
        }

        if (! empty($batch)) {
            $this->flush($batch);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     */
    private function flush(array $batch): void
    {
        BrokerSummaryDaily::query()->upsert(
            $batch,
            ['instrument_id', 'trade_date', 'broker_code'],
            [
                'broker_id', 'broker_category', 'buy_volume', 'sell_volume', 'net_volume',
                'buy_value', 'sell_value', 'net_value', 'avg_buy_price', 'avg_sell_price',
                'currency', 'data_source_id', 'is_demo', 'updated_at',
            ]
        );
    }
}
