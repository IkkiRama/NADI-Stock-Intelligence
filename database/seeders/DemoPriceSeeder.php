<?php

namespace Database\Seeders;

use App\Domain\MarketData\Actions\RefreshLatestQuotes;
use App\Domain\MarketData\Providers\DemoMarketDataProvider;
use App\Models\DataSource;
use App\Models\IndexValueDaily;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use App\Models\StockIndex;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DemoPriceSeeder extends Seeder
{
    public function run(DemoMarketDataProvider $provider, RefreshLatestQuotes $refreshQuotes): void
    {
        if (! config('nadi.market_data.demo.enabled', true)) {
            return;
        }

        $source = DataSource::query()->where('code', 'demo')->first();
        if (! $source || ! $source->is_enabled) {
            return;
        }

        $targetWeekdays = (int) config('nadi.market_data.demo.history_weekdays', 260);
        $end = CarbonImmutable::now('UTC')->subDay()->startOfDay();
        while ($end->isWeekend()) {
            $end = $end->subDay();
        }

        $counted = 0;
        $cursor = $end;
        while ($counted < $targetWeekdays) {
            if (! $cursor->isWeekend()) {
                $counted++;
                if ($counted === $targetWeekdays) {
                    break;
                }
            }
            $cursor = $cursor->subDay();
        }
        $start = $cursor;
        $now = now('UTC');

        $instrumentRows = [];
        Instrument::query()->with('exchange')->where('is_active', true)->chunkById(50, function ($instruments) use ($provider, $start, $end, $source, $now, &$instrumentRows): void {
            foreach ($instruments as $instrument) {
                foreach ($provider->dailyBars($instrument, $start, $end) as $bar) {
                    $instrumentRows[] = [
                        'instrument_id' => $instrument->id,
                        'trade_date' => $bar['trade_date'],
                        'open' => $bar['open'],
                        'high' => $bar['high'],
                        'low' => $bar['low'],
                        'close' => $bar['close'],
                        'adj_close' => $bar['close'],
                        'volume' => $bar['volume'],
                        'value_traded' => $bar['value_traded'],
                        'data_source_id' => $source->id,
                        'is_demo' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($instrumentRows) >= 500) {
                        InstrumentPriceDaily::query()->upsert(
                            $instrumentRows,
                            ['instrument_id', 'trade_date'],
                            ['open', 'high', 'low', 'close', 'adj_close', 'volume', 'value_traded', 'data_source_id', 'is_demo', 'updated_at']
                        );
                        $instrumentRows = [];
                    }
                }
            }
        });

        if (! empty($instrumentRows)) {
            InstrumentPriceDaily::query()->upsert(
                $instrumentRows,
                ['instrument_id', 'trade_date'],
                ['open', 'high', 'low', 'close', 'adj_close', 'volume', 'value_traded', 'data_source_id', 'is_demo', 'updated_at']
            );
        }

        $indexRows = [];
        StockIndex::query()->with('exchange')->where('is_active', true)->chunkById(50, function ($indices) use ($provider, $start, $end, $source, $now, &$indexRows): void {
            foreach ($indices as $index) {
                foreach ($provider->dailyBars($index, $start, $end) as $bar) {
                    $indexRows[] = [
                        'stock_index_id' => $index->id,
                        'trade_date' => $bar['trade_date'],
                        'open' => $bar['open'],
                        'high' => $bar['high'],
                        'low' => $bar['low'],
                        'close' => $bar['close'],
                        'volume' => $bar['volume'],
                        'data_source_id' => $source->id,
                        'is_demo' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($indexRows) >= 500) {
                        IndexValueDaily::query()->upsert(
                            $indexRows,
                            ['stock_index_id', 'trade_date'],
                            ['open', 'high', 'low', 'close', 'volume', 'data_source_id', 'is_demo', 'updated_at']
                        );
                        $indexRows = [];
                    }
                }
            }
        });

        if (! empty($indexRows)) {
            IndexValueDaily::query()->upsert(
                $indexRows,
                ['stock_index_id', 'trade_date'],
                ['open', 'high', 'low', 'close', 'volume', 'data_source_id', 'is_demo', 'updated_at']
            );
        }

        $source->forceFill(['last_success_at' => $now])->save();
        $refreshQuotes->execute();
    }
}
