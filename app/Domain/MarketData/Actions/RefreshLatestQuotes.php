<?php

namespace App\Domain\MarketData\Actions;

use App\Models\IndexQuoteLatest;
use App\Models\IndexValueDaily;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use App\Models\InstrumentQuoteLatest;
use App\Models\StockIndex;
use App\Support\Math\Dec;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class RefreshLatestQuotes
{
    public const CACHE_VERSION_KEY = 'nadi:quotes:version';

    /**
     * Refresh precomputed latest quotes for all instruments and stock indices.
     * Avoids SQL window functions for MySQL 5.7+ / MariaDB 10.3+ compatibility.
     *
     * @return array{instruments: int, indices: int}
     */
    public function execute(): array
    {
        $now = now('UTC');
        $instrumentRows = [];
        $indexRows = [];

        Instrument::query()->select('id')->chunkById(200, function ($instruments) use (&$instrumentRows, $now): void {
            foreach ($instruments as $instrument) {
                $bars = InstrumentPriceDaily::query()
                    ->where('instrument_id', $instrument->id)
                    ->orderByDesc('trade_date')
                    ->limit(2)
                    ->get();

                if ($bars->isEmpty()) {
                    continue;
                }

                $latest = $bars[0];
                $prev = $bars[1] ?? null;

                $close = (string) $latest->close;
                $prevClose = $prev ? (string) $prev->close : null;
                $change = $prevClose !== null ? Dec::sub($close, $prevClose, 6) : null;
                $changePct = $prevClose !== null ? Dec::pctChange($close, $prevClose, 8) : null;

                $instrumentRows[] = [
                    'instrument_id' => $instrument->id,
                    'trade_date' => $latest->trade_date->toDateString(),
                    'close' => Dec::round($close, 6),
                    'prev_close' => $prevClose !== null ? Dec::round($prevClose, 6) : null,
                    'change' => $change,
                    'change_pct' => $changePct,
                    'volume' => (int) $latest->volume,
                    'value_traded' => $latest->value_traded !== null ? Dec::round((string) $latest->value_traded, 4) : null,
                    'data_source_id' => $latest->data_source_id,
                    'is_demo' => (bool) $latest->is_demo,
                    'refreshed_at' => $now,
                ];
            }
        });

        StockIndex::query()->select('id')->chunkById(100, function ($indices) use (&$indexRows, $now): void {
            foreach ($indices as $index) {
                $bars = IndexValueDaily::query()
                    ->where('stock_index_id', $index->id)
                    ->orderByDesc('trade_date')
                    ->limit(2)
                    ->get();

                if ($bars->isEmpty()) {
                    continue;
                }

                $latest = $bars[0];
                $prev = $bars[1] ?? null;

                $close = (string) $latest->close;
                $prevClose = $prev ? (string) $prev->close : null;
                $change = $prevClose !== null ? Dec::sub($close, $prevClose, 6) : null;
                $changePct = $prevClose !== null ? Dec::pctChange($close, $prevClose, 8) : null;

                $indexRows[] = [
                    'stock_index_id' => $index->id,
                    'trade_date' => $latest->trade_date->toDateString(),
                    'close' => Dec::round($close, 6),
                    'prev_close' => $prevClose !== null ? Dec::round($prevClose, 6) : null,
                    'change' => $change,
                    'change_pct' => $changePct,
                    'volume' => $latest->volume !== null ? (int) $latest->volume : null,
                    'data_source_id' => $latest->data_source_id,
                    'is_demo' => (bool) $latest->is_demo,
                    'refreshed_at' => $now,
                ];
            }
        });

        DB::transaction(function () use ($instrumentRows, $indexRows): void {
            if (! empty($instrumentRows)) {
                foreach (array_chunk($instrumentRows, 250) as $chunk) {
                    InstrumentQuoteLatest::query()->upsert(
                        $chunk,
                        ['instrument_id'],
                        ['trade_date', 'close', 'prev_close', 'change', 'change_pct', 'volume', 'value_traded', 'data_source_id', 'is_demo', 'refreshed_at']
                    );
                }
            }

            if (! empty($indexRows)) {
                foreach (array_chunk($indexRows, 100) as $chunk) {
                    IndexQuoteLatest::query()->upsert(
                        $chunk,
                        ['stock_index_id'],
                        ['trade_date', 'close', 'prev_close', 'change', 'change_pct', 'volume', 'data_source_id', 'is_demo', 'refreshed_at']
                    );
                }
            }
        });

        $currentVersion = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $currentVersion + 1);

        return [
            'instruments' => count($instrumentRows),
            'indices' => count($indexRows),
        ];
    }
}
