<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\Services\PriceReturnService;
use App\Domain\MarketData\Services\MarketOverviewService;
use App\Models\IndexValueDaily;
use App\Models\RecentView;
use App\Models\StockIndex;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketController extends Controller
{
    public function index(MarketOverviewService $overview, PriceReturnService $returns): Response
    {
        $cards = $overview->indexCards();
        $enriched = [];

        foreach ($cards as $card) {
            $closes = IndexValueDaily::query()
                ->where('stock_index_id', $card['id'])
                ->orderBy('trade_date')
                ->pluck('close', 'trade_date')
                ->mapWithKeys(fn ($v, $k) => [substr((string) $k, 0, 10) => (string) $v])
                ->all();

            $card['return_1m'] = $returns->periodReturn($closes, '1M');
            $card['return_ytd'] = $returns->periodReturn($closes, 'YTD');
            $card['return_1y'] = $returns->periodReturn($closes, '1Y');

            $enriched[$card['country_code']][] = $card;
        }

        return Inertia::render('markets/index', [
            'indicesByCountry' => $enriched,
            'clocks' => $overview->clocks(),
        ]);
    }

    public function showIndex(Request $request, string $code, PriceReturnService $returns): Response
    {
        $index = StockIndex::query()
            ->with(['exchange:id,code,name,timezone', 'latestQuote.dataSource:id,code,name'])
            ->whereRaw('LOWER(code) = ?', [strtolower($code)])
            ->where('is_active', true)
            ->firstOrFail();

        RecentView::query()->upsert(
            [[
                'user_id' => $request->user()->id,
                'viewable_type' => StockIndex::class,
                'viewable_id' => $index->id,
                'viewed_at' => now('UTC'),
            ]],
            ['user_id', 'viewable_type', 'viewable_id'],
            ['viewed_at']
        );

        $barsCollection = IndexValueDaily::query()
            ->where('stock_index_id', $index->id)
            ->orderByDesc('trade_date')
            ->limit(260)
            ->get()
            ->reverse()
            ->values();

        $closes = [];
        $highs = [];
        $lows = [];
        $bars = [];

        foreach ($barsCollection as $bar) {
            $d = $bar->trade_date->toDateString();
            $closes[$d] = (string) $bar->close;
            $highs[$d] = (string) $bar->high;
            $lows[$d] = (string) $bar->low;
            $bars[] = [
                'date' => $d,
                'open' => (string) $bar->open,
                'high' => (string) $bar->high,
                'low' => (string) $bar->low,
                'close' => (string) $bar->close,
                'volume' => $bar->volume !== null ? (int) $bar->volume : 0,
            ];
        }

        $quote = $index->latestQuote;

        return Inertia::render('markets/index-show', [
            'index' => [
                'id' => $index->id,
                'code' => $index->code,
                'name' => $index->name,
                'country_code' => $index->country_code,
                'currency' => $index->currency,
                'index_type' => $index->index_type,
                'exchange' => $index->exchange ? [
                    'code' => $index->exchange->code,
                    'name' => $index->exchange->name,
                    'timezone' => $index->exchange->timezone,
                ] : null,
                'quote' => $quote ? [
                    'close' => (string) $quote->close,
                    'prev_close' => $quote->prev_close !== null ? (string) $quote->prev_close : null,
                    'change' => $quote->change !== null ? (string) $quote->change : null,
                    'change_pct' => $quote->change_pct !== null ? (string) $quote->change_pct : null,
                    'as_of' => $quote->trade_date->toDateString(),
                    'is_demo' => (bool) $quote->is_demo,
                    'source' => $quote->dataSource?->name ?? 'Demo',
                ] : null,
            ],
            'stats' => [
                'high_52w' => $returns->high52w($highs),
                'low_52w' => $returns->low52w($lows),
                'return_1m' => $returns->periodReturn($closes, '1M'),
                'return_3m' => $returns->periodReturn($closes, '3M'),
                'return_6m' => $returns->periodReturn($closes, '6M'),
                'return_ytd' => $returns->periodReturn($closes, 'YTD'),
                'return_1y' => $returns->periodReturn($closes, '1Y'),
            ],
            'bars' => $bars,
            'constituents' => [],
        ]);
    }
}
