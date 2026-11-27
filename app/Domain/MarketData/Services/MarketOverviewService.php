<?php

namespace App\Domain\MarketData\Services;

use App\Domain\MarketData\Actions\RefreshLatestQuotes;
use App\Models\Exchange;
use App\Models\IndexValueDaily;
use App\Models\Instrument;
use App\Models\InstrumentQuoteLatest;
use App\Models\StockIndex;
use App\Support\Math\Dec;
use Illuminate\Support\Facades\Cache;

final class MarketOverviewService
{
    public function __construct(
        private readonly MarketClockService $clockService,
    ) {}

    private function cacheVersion(): int
    {
        return (int) Cache::get(RefreshLatestQuotes::CACHE_VERSION_KEY, 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function indexCards(): array
    {
        $key = 'nadi:overview:indices:v'.$this->cacheVersion();

        return Cache::remember($key, now()->addMinutes(10), function (): array {
            $indices = StockIndex::query()
                ->with(['exchange:id,code,name,timezone', 'latestQuote.dataSource:id,code,name'])
                ->where('is_active', true)
                ->orderBy('id')
                ->get();

            return $indices->map(function (StockIndex $index): array {
                $quote = $index->latestQuote;
                $sparkline = IndexValueDaily::query()
                    ->where('stock_index_id', $index->id)
                    ->orderByDesc('trade_date')
                    ->limit(30)
                    ->pluck('close')
                    ->reverse()
                    ->values()
                    ->map(fn ($v) => (string) $v)
                    ->all();

                return [
                    'id' => $index->id,
                    'code' => $index->code,
                    'name' => $index->name,
                    'country_code' => $index->country_code,
                    'currency' => $index->currency,
                    'index_type' => $index->index_type,
                    'exchange_code' => $index->exchange?->code,
                    'close' => $quote ? (string) $quote->close : null,
                    'prev_close' => $quote?->prev_close !== null ? (string) $quote->prev_close : null,
                    'change' => $quote?->change !== null ? (string) $quote->change : null,
                    'change_pct' => $quote?->change_pct !== null ? (string) $quote->change_pct : null,
                    'as_of' => $quote?->trade_date?->toDateString(),
                    'is_demo' => (bool) ($quote?->is_demo ?? false),
                    'source' => $quote?->dataSource?->name ?? 'Demo',
                    'sparkline' => $sparkline,
                ];
            })->all();
        });
    }

    /**
     * @return array{advancers: int, decliners: int, unchanged: int, total: int, as_of: string|null, is_demo: bool}
     */
    public function breadth(?string $exchangeCode = null): array
    {
        $code = $exchangeCode ? strtoupper($exchangeCode) : 'ALL';
        $key = "nadi:overview:breadth:{$code}:v".$this->cacheVersion();

        return Cache::remember($key, now()->addMinutes(10), function () use ($exchangeCode): array {
            $query = InstrumentQuoteLatest::query()
                ->join('instruments', 'instruments.id', '=', 'instrument_quotes_latest.instrument_id')
                ->join('exchanges', 'exchanges.id', '=', 'instruments.exchange_id')
                ->where('instruments.is_active', true);

            if ($exchangeCode && strtoupper($exchangeCode) !== 'ALL') {
                $query->where('exchanges.code', strtoupper($exchangeCode));
            }

            $rows = $query->select([
                'instrument_quotes_latest.change',
                'instrument_quotes_latest.trade_date',
                'instrument_quotes_latest.is_demo',
            ])->get();

            $adv = 0;
            $dec = 0;
            $unc = 0;
            $asOf = null;
            $isDemo = false;

            foreach ($rows as $row) {
                $isDemo = $isDemo || (bool) $row->is_demo;
                $dateStr = $row->trade_date instanceof \Carbon\CarbonInterface
                    ? $row->trade_date->toDateString()
                    : (string) $row->trade_date;
                if ($asOf === null || $dateStr > $asOf) {
                    $asOf = $dateStr;
                }

                if ($row->change === null) {
                    $unc++;
                    continue;
                }

                $cmp = Dec::cmp((string) $row->change, '0');
                if ($cmp > 0) {
                    $adv++;
                } elseif ($cmp < 0) {
                    $dec++;
                } else {
                    $unc++;
                }
            }

            return [
                'advancers' => $adv,
                'decliners' => $dec,
                'unchanged' => $unc,
                'total' => $rows->count(),
                'as_of' => $asOf,
                'is_demo' => $isDemo,
            ];
        });
    }

    /**
     * @return array{gainers: list<array<string, mixed>>, losers: list<array<string, mixed>>}
     */
    public function topMovers(?string $exchangeCode = null, int $limit = 5): array
    {
        $code = $exchangeCode ? strtoupper($exchangeCode) : 'ALL';
        $key = "nadi:overview:movers:{$code}:{$limit}:v".$this->cacheVersion();

        return Cache::remember($key, now()->addMinutes(10), function () use ($exchangeCode, $limit): array {
            $baseQuery = Instrument::query()
                ->with(['exchange:id,code,currency', 'latestQuote.dataSource:id,code,name'])
                ->where('is_active', true)
                ->whereHas('latestQuote', fn ($q) => $q->whereNotNull('change_pct'));

            if ($exchangeCode && strtoupper($exchangeCode) !== 'ALL') {
                $baseQuery->whereHas('exchange', fn ($q) => $q->where('code', strtoupper($exchangeCode)));
            }

            $items = $baseQuery->get()->map(function (Instrument $inst): array {
                $q = $inst->latestQuote;

                return [
                    'id' => $inst->id,
                    'symbol' => $inst->symbol,
                    'name' => $inst->name,
                    'exchange_code' => $inst->exchange->code,
                    'currency' => $inst->currency,
                    'sector' => $inst->sector,
                    'close' => (string) $q->close,
                    'change' => $q->change !== null ? (string) $q->change : null,
                    'change_pct' => $q->change_pct !== null ? (string) $q->change_pct : null,
                    'volume' => (int) $q->volume,
                    'as_of' => $q->trade_date->toDateString(),
                    'is_demo' => (bool) $q->is_demo,
                ];
            });

            $sortedDesc = $items->sort(fn ($a, $b) => Dec::cmp($b['change_pct'] ?? '0', $a['change_pct'] ?? '0'))->values();
            $sortedAsc = $items->sort(fn ($a, $b) => Dec::cmp($a['change_pct'] ?? '0', $b['change_pct'] ?? '0'))->values();

            return [
                'gainers' => $sortedDesc->take($limit)->values()->all(),
                'losers' => $sortedAsc->take($limit)->values()->all(),
            ];
        });
    }

    /**
     * @return list<array{sector: string, avg_change_pct: string, count: int}>
     */
    public function sectorPerformance(?string $exchangeCode = null): array
    {
        $code = $exchangeCode ? strtoupper($exchangeCode) : 'ALL';
        $key = "nadi:overview:sectors:{$code}:v".$this->cacheVersion();

        return Cache::remember($key, now()->addMinutes(10), function () use ($exchangeCode): array {
            $query = Instrument::query()
                ->join('instrument_quotes_latest', 'instrument_quotes_latest.instrument_id', '=', 'instruments.id')
                ->join('exchanges', 'exchanges.id', '=', 'instruments.exchange_id')
                ->where('instruments.is_active', true)
                ->whereNotNull('instruments.sector')
                ->whereNotNull('instrument_quotes_latest.change_pct');

            if ($exchangeCode && strtoupper($exchangeCode) !== 'ALL') {
                $query->where('exchanges.code', strtoupper($exchangeCode));
            }

            $rows = $query->select(['instruments.sector', 'instrument_quotes_latest.change_pct'])->get();

            $grouped = [];
            foreach ($rows as $row) {
                $sector = (string) $row->sector;
                $grouped[$sector] ??= ['sum' => '0', 'count' => 0];
                $grouped[$sector]['sum'] = Dec::add($grouped[$sector]['sum'], (string) $row->change_pct);
                $grouped[$sector]['count']++;
            }

            $result = [];
            foreach ($grouped as $sector => $agg) {
                $avg = Dec::div($agg['sum'], (string) $agg['count'], 8) ?? '0';
                $result[] = [
                    'sector' => $sector,
                    'avg_change_pct' => $avg,
                    'count' => $agg['count'],
                ];
            }

            usort($result, fn ($a, $b) => Dec::cmp($b['avg_change_pct'], $a['avg_change_pct']));

            return $result;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function clocks(): array
    {
        $exchanges = Exchange::query()
            ->with('holidays')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        return $exchanges->map(function (Exchange $exchange): array {
            return [
                'code' => $exchange->code,
                'name' => $exchange->name,
                'country_code' => $exchange->country_code,
                'currency' => $exchange->currency,
                'status' => $this->clockService->status($exchange),
            ];
        })->all();
    }
}
