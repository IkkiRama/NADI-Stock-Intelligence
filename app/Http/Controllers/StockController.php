<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\Services\PriceReturnService;
use App\Domain\MarketData\Services\MarketClockService;
use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use App\Models\RecentView;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    public function index(Request $request): Response
    {
        $exchangeFilter = strtoupper(trim((string) $request->query('exchange', '')));
        $sectorFilter = trim((string) $request->query('sector', ''));
        $search = trim((string) $request->query('q', ''));
        $sort = (string) $request->query('sort', 'symbol');
        $dir = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $sortMap = [
            'symbol' => 'instruments.symbol',
            'name' => 'instruments.name',
            'close' => 'instrument_quotes_latest.close',
            'change_pct' => 'instrument_quotes_latest.change_pct',
            'volume' => 'instrument_quotes_latest.volume',
        ];
        $orderColumn = $sortMap[$sort] ?? 'instruments.symbol';

        $query = Instrument::query()
            ->join('exchanges', 'exchanges.id', '=', 'instruments.exchange_id')
            ->leftJoin('instrument_quotes_latest', 'instrument_quotes_latest.instrument_id', '=', 'instruments.id')
            ->where('instruments.is_active', true)
            ->select([
                'instruments.id',
                'instruments.symbol',
                'instruments.name',
                'instruments.sector',
                'instruments.currency',
                'instruments.country_code',
                'exchanges.code as exchange_code',
                'instrument_quotes_latest.close',
                'instrument_quotes_latest.change',
                'instrument_quotes_latest.change_pct',
                'instrument_quotes_latest.volume',
                'instrument_quotes_latest.trade_date as as_of',
                'instrument_quotes_latest.is_demo',
            ]);

        if ($exchangeFilter !== '' && $exchangeFilter !== 'ALL') {
            $query->where('exchanges.code', $exchangeFilter);
        }

        if ($sectorFilter !== '' && $sectorFilter !== 'ALL') {
            $query->where('instruments.sector', $sectorFilter);
        }

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($q) use ($like): void {
                $q->where('instruments.symbol', 'like', $like)
                    ->orWhere('instruments.name', 'like', $like);
            });
        }

        $query->orderBy($orderColumn, $dir)->orderBy('instruments.symbol');

        $stocks = $query->paginate(25)->withQueryString()->through(function ($row): array {
            return [
                'id' => (int) $row->id,
                'symbol' => $row->symbol,
                'name' => $row->name,
                'sector' => $row->sector,
                'currency' => $row->currency,
                'country_code' => $row->country_code,
                'exchange_code' => $row->exchange_code,
                'close' => $row->close !== null ? (string) $row->close : null,
                'change' => $row->change !== null ? (string) $row->change : null,
                'change_pct' => $row->change_pct !== null ? (string) $row->change_pct : null,
                'volume' => $row->volume !== null ? (int) $row->volume : null,
                'as_of' => $row->as_of ? substr((string) $row->as_of, 0, 10) : null,
                'is_demo' => (bool) $row->is_demo,
            ];
        });

        $watchlist = $request->user()->watchlists()->where('is_default', true)->first();
        $watchlistedIds = $watchlist
            ? $watchlist->items()->pluck('instrument_id')->map(fn ($id) => (int) $id)->all()
            : [];

        return Inertia::render('stocks/index', [
            'stocks' => $stocks,
            'exchanges' => Exchange::query()->where('is_active', true)->orderBy('id')->get(['code', 'name']),
            'sectors' => Instrument::query()->where('is_active', true)->whereNotNull('sector')->distinct()->orderBy('sector')->pluck('sector')->values()->all(),
            'watchlistedIds' => $watchlistedIds,
            'filters' => [
                'exchange' => $exchangeFilter,
                'sector' => $sectorFilter,
                'q' => $search,
                'sort' => array_key_exists($sort, $sortMap) ? $sort : 'symbol',
                'dir' => $dir,
            ],
        ]);
    }

    public function show(
        Request $request,
        string $exchange,
        string $symbol,
        PriceReturnService $returns,
        MarketClockService $clock,
    ): Response {
        $exchangeModel = Exchange::query()
            ->with('holidays')
            ->whereRaw('LOWER(code) = ?', [strtolower($exchange)])
            ->where('is_active', true)
            ->firstOrFail();

        $instrument = Instrument::query()
            ->with(['latestQuote.dataSource:id,code,name'])
            ->where('exchange_id', $exchangeModel->id)
            ->whereRaw('LOWER(symbol) = ?', [strtolower($symbol)])
            ->where('is_active', true)
            ->firstOrFail();

        RecentView::query()->upsert(
            [[
                'user_id' => $request->user()->id,
                'viewable_type' => Instrument::class,
                'viewable_id' => $instrument->id,
                'viewed_at' => now('UTC'),
            ]],
            ['user_id', 'viewable_type', 'viewable_id'],
            ['viewed_at']
        );

        $barsCollection = InstrumentPriceDaily::query()
            ->where('instrument_id', $instrument->id)
            ->orderByDesc('trade_date')
            ->limit(260)
            ->get()
            ->reverse()
            ->values();

        $closes = [];
        $highs = [];
        $lows = [];
        $volumes = [];
        $bars = [];

        foreach ($barsCollection as $bar) {
            $d = $bar->trade_date->toDateString();
            $closes[$d] = (string) $bar->close;
            $highs[$d] = (string) $bar->high;
            $lows[$d] = (string) $bar->low;
            $volumes[] = (int) $bar->volume;
            $bars[] = [
                'date' => $d,
                'open' => (string) $bar->open,
                'high' => (string) $bar->high,
                'low' => (string) $bar->low,
                'close' => (string) $bar->close,
                'volume' => (int) $bar->volume,
                'value_traded' => $bar->value_traded !== null ? (string) $bar->value_traded : null,
            ];
        }

        $latestBar = $barsCollection->last();
        $quote = $instrument->latestQuote;
        $watchlist = $request->user()->watchlists()->where('is_default', true)->first();
        $inWatchlist = $watchlist
            ? $watchlist->items()->where('instrument_id', $instrument->id)->exists()
            : false;

        return Inertia::render('stocks/show', [
            'instrument' => [
                'id' => $instrument->id,
                'symbol' => $instrument->symbol,
                'name' => $instrument->name,
                'type' => $instrument->type,
                'currency' => $instrument->currency,
                'sector' => $instrument->sector,
                'industry' => $instrument->industry,
                'country_code' => $instrument->country_code,
                'lot_size' => $instrument->lot_size ?? $exchangeModel->default_lot_size,
                'lot_size_verified' => (bool) ($instrument->metadata['lot_size_verified'] ?? true),
                'exchange' => [
                    'code' => $exchangeModel->code,
                    'name' => $exchangeModel->name,
                    'timezone' => $exchangeModel->timezone,
                    'clock' => $clock->status($exchangeModel),
                ],
                'quote' => $quote ? [
                    'close' => (string) $quote->close,
                    'prev_close' => $quote->prev_close !== null ? (string) $quote->prev_close : null,
                    'change' => $quote->change !== null ? (string) $quote->change : null,
                    'change_pct' => $quote->change_pct !== null ? (string) $quote->change_pct : null,
                    'volume' => (int) $quote->volume,
                    'value_traded' => $quote->value_traded !== null ? (string) $quote->value_traded : null,
                    'open' => $latestBar ? (string) $latestBar->open : null,
                    'high' => $latestBar ? (string) $latestBar->high : null,
                    'low' => $latestBar ? (string) $latestBar->low : null,
                    'as_of' => $quote->trade_date->toDateString(),
                    'is_demo' => (bool) $quote->is_demo,
                    'source' => $quote->dataSource?->name ?? 'Demo',
                ] : null,
            ],
            'stats' => [
                'high_52w' => $returns->high52w($highs),
                'low_52w' => $returns->low52w($lows),
                'avg_volume_20d' => $returns->avgVolume($volumes, 20),
                'return_1m' => $returns->periodReturn($closes, '1M'),
                'return_3m' => $returns->periodReturn($closes, '3M'),
                'return_6m' => $returns->periodReturn($closes, '6M'),
                'return_ytd' => $returns->periodReturn($closes, 'YTD'),
                'return_1y' => $returns->periodReturn($closes, '1Y'),
            ],
            'bars' => $bars,
            'inWatchlist' => $inWatchlist,
        ]);
    }
}
