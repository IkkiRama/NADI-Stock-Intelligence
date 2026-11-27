<?php

namespace App\Http\Controllers;

use App\Models\Exchange;
use App\Models\Instrument;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HeatmapController extends Controller
{
    public function index(Request $request): Response
    {
        $scope = $request->query('scope', 'market') === 'watchlist' ? 'watchlist' : 'market';
        $exchangeFilter = strtoupper(trim((string) $request->query('exchange', 'ALL')));

        $query = Instrument::query()
            ->join('exchanges', 'exchanges.id', '=', 'instruments.exchange_id')
            ->leftJoin('instrument_quotes_latest', 'instrument_quotes_latest.instrument_id', '=', 'instruments.id')
            ->leftJoin('instrument_fundamentals_latest', 'instrument_fundamentals_latest.instrument_id', '=', 'instruments.id')
            ->leftJoin('instrument_technicals_latest', 'instrument_technicals_latest.instrument_id', '=', 'instruments.id')
            ->leftJoin('instrument_scores', 'instrument_scores.instrument_id', '=', 'instruments.id')
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
                'instrument_quotes_latest.change_pct',
                'instrument_quotes_latest.volume',
                'instrument_quotes_latest.value_traded',
                'instrument_quotes_latest.trade_date as as_of',
                'instrument_quotes_latest.is_demo',
                'instrument_fundamentals_latest.market_cap',
                'instrument_fundamentals_latest.market_cap_idr',
                'instrument_technicals_latest.return_1w',
                'instrument_technicals_latest.return_1m',
                'instrument_technicals_latest.return_3m',
                'instrument_technicals_latest.return_1y',
                'instrument_technicals_latest.volatility_20d',
                'instrument_scores.overall_score',
            ]);

        if ($exchangeFilter !== '' && $exchangeFilter !== 'ALL') {
            $query->where('exchanges.code', $exchangeFilter);
        }

        if ($scope === 'watchlist') {
            $watchlist = $request->user()->watchlists()->where('is_default', true)->first();
            $ids = $watchlist ? $watchlist->items()->pluck('instrument_id')->all() : [];
            $query->whereIn('instruments.id', empty($ids) ? [-1] : $ids);
        }

        $rows = $query->orderBy('instruments.sector')->orderBy('instruments.symbol')->get();

        $grouped = [];
        $latestAsOf = null;
        $anyDemo = false;

        foreach ($rows as $row) {
            $sector = $row->sector ?: 'Other';
            $asOf = $row->as_of ? substr((string) $row->as_of, 0, 10) : null;
            if ($asOf && ($latestAsOf === null || $asOf > $latestAsOf)) {
                $latestAsOf = $asOf;
            }
            $anyDemo = $anyDemo || (bool) $row->is_demo;

            $grouped[$sector][] = [
                'id' => (int) $row->id,
                'symbol' => $row->symbol,
                'name' => $row->name,
                'exchange_code' => $row->exchange_code,
                'currency' => $row->currency,
                'country_code' => $row->country_code,
                'sector' => $sector,
                'close' => $row->close !== null ? (string) $row->close : null,
                'market_cap' => $row->market_cap !== null ? (string) $row->market_cap : null,
                'market_cap_idr' => $row->market_cap_idr !== null ? (string) $row->market_cap_idr : null,
                'volume' => $row->volume !== null ? (int) $row->volume : 0,
                'value_traded' => $row->value_traded !== null ? (string) $row->value_traded : null,
                'change_pct' => $row->change_pct !== null ? (string) $row->change_pct : null,
                'return_1w' => $row->return_1w !== null ? (string) $row->return_1w : null,
                'return_1m' => $row->return_1m !== null ? (string) $row->return_1m : null,
                'return_3m' => $row->return_3m !== null ? (string) $row->return_3m : null,
                'return_1y' => $row->return_1y !== null ? (string) $row->return_1y : null,
                'volatility_20d' => $row->volatility_20d !== null ? (string) $row->volatility_20d : null,
                'overall_score' => $row->overall_score !== null ? (string) $row->overall_score : null,
                'as_of' => $asOf,
                'is_demo' => (bool) $row->is_demo,
            ];
        }

        $sectors = [];
        foreach ($grouped as $sector => $children) {
            $sectors[] = [
                'sector' => $sector,
                'children' => $children,
            ];
        }

        return Inertia::render('markets/heatmap', [
            'sectors' => $sectors,
            'exchanges' => Exchange::query()->where('is_active', true)->orderBy('id')->get(['code', 'name']),
            'filters' => [
                'scope' => $scope,
                'exchange' => $exchangeFilter === '' ? 'ALL' : $exchangeFilter,
            ],
            'asOf' => $latestAsOf,
            'isDemo' => $anyDemo,
        ]);
    }
}
