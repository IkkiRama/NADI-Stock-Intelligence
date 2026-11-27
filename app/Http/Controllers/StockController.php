<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\Services\PriceReturnService;
use App\Domain\Analytics\Services\TechnicalIndicatorService;
use App\Domain\MarketData\Actions\RefreshMarketIntelligence;
use App\Domain\MarketData\Services\MarketClockService;
use App\Domain\Newsroom\Services\NewsroomService;
use App\Domain\Portfolio\Services\PortfolioValuationService;
use App\Domain\Research\Services\ResearchCenterService;
use App\Domain\TradingSignals\Services\SmartEntryExitService;
use App\Models\Exchange;
use App\Models\IndexValueDaily;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use App\Models\RecentView;
use App\Models\StockIndex;
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
            'overall_score' => 'instrument_scores.overall_score',
        ];
        $orderColumn = $sortMap[$sort] ?? 'instruments.symbol';

        $query = Instrument::query()
            ->join('exchanges', 'exchanges.id', '=', 'instruments.exchange_id')
            ->leftJoin('instrument_quotes_latest', 'instrument_quotes_latest.instrument_id', '=', 'instruments.id')
            ->leftJoin('instrument_fundamentals_latest', 'instrument_fundamentals_latest.instrument_id', '=', 'instruments.id')
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
                'instrument_quotes_latest.change',
                'instrument_quotes_latest.change_pct',
                'instrument_quotes_latest.volume',
                'instrument_quotes_latest.trade_date as as_of',
                'instrument_quotes_latest.is_demo',
                'instrument_fundamentals_latest.market_cap',
                'instrument_scores.overall_score',
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
                'market_cap' => $row->market_cap !== null ? (string) $row->market_cap : null,
                'overall_score' => $row->overall_score !== null ? (string) $row->overall_score : null,
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
        TechnicalIndicatorService $technicalsService,
        MarketClockService $clock,
        PortfolioValuationService $valuation,
        NewsroomService $newsroom,
        ResearchCenterService $research,
    ): Response {
        $exchangeModel = Exchange::query()
            ->with('holidays')
            ->whereRaw('LOWER(code) = ?', [strtolower($exchange)])
            ->where('is_active', true)
            ->firstOrFail();

        $instrument = Instrument::query()
            ->with([
                'latestQuote.dataSource:id,code,name',
                'latestFundamentals.dataSource:id,code,name',
                'latestTechnicals',
                'latestScore',
                'scoreHistory',
                'corporateActions',
                'financialStatements',
                'regulatoryStatus',
                'safetyAssessment',
                'safetyHistory',
                'tradingSignals',
            ])
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

        $indicatorSeries = $technicalsService->computeSeries($bars);

        // Optional benchmark comparison overlay (country's primary index)
        $benchmarkCode = RefreshMarketIntelligence::COUNTRY_BENCHMARK_MAP[$instrument->country_code] ?? null;
        $benchmarkOverlay = null;
        if ($benchmarkCode && ! empty($bars)) {
            $benchIndex = StockIndex::query()->where('code', $benchmarkCode)->first();
            if ($benchIndex) {
                $minDate = $bars[0]['date'];
                $benchBars = IndexValueDaily::query()
                    ->where('stock_index_id', $benchIndex->id)
                    ->whereDate('trade_date', '>=', $minDate)
                    ->orderBy('trade_date')
                    ->get(['trade_date', 'close'])
                    ->map(fn ($b) => ['date' => $b->trade_date->toDateString(), 'close' => (string) $b->close])
                    ->all();

                $benchmarkOverlay = [
                    'code' => $benchIndex->code,
                    'name' => $benchIndex->name,
                    'points' => $benchBars,
                ];
            }
        }

        $latestBar = $barsCollection->last();
        $quote = $instrument->latestQuote;
        $watchlist = $request->user()->watchlists()->where('is_default', true)->first();
        $inWatchlist = $watchlist
            ? $watchlist->items()->where('instrument_id', $instrument->id)->exists()
            : false;

        $fund = $instrument->latestFundamentals;
        $tech = $instrument->latestTechnicals;
        $score = $instrument->latestScore;
        $safety = $instrument->safetyAssessment;
        $regulatory = $instrument->regulatoryStatus;

        $signalsByHorizon = [];
        foreach (SmartEntryExitService::HORIZONS as $h) {
            $sig = $instrument->tradingSignals->where('horizon', $h)->firstWhere('is_latest', true);
            $signalsByHorizon[$h] = $sig ? [
                'id' => $sig->id,
                'horizon' => $sig->horizon,
                'signal_date' => $sig->signal_date->toDateString(),
                'stance' => $sig->stance,
                'entry_low' => $sig->entry_low !== null ? (string) $sig->entry_low : null,
                'entry_high' => $sig->entry_high !== null ? (string) $sig->entry_high : null,
                'confirmation_price' => $sig->confirmation_price !== null ? (string) $sig->confirmation_price : null,
                'stop_loss' => $sig->stop_loss !== null ? (string) $sig->stop_loss : null,
                'invalidation_price' => $sig->invalidation_price !== null ? (string) $sig->invalidation_price : null,
                'target_1' => $sig->target_1 !== null ? (string) $sig->target_1 : null,
                'target_2' => $sig->target_2 !== null ? (string) $sig->target_2 : null,
                'trailing_stop_atr_mult' => $sig->trailing_stop_atr_mult !== null ? (string) $sig->trailing_stop_atr_mult : null,
                'support_level' => $sig->support_level !== null ? (string) $sig->support_level : null,
                'resistance_level' => $sig->resistance_level !== null ? (string) $sig->resistance_level : null,
                'gross_rr' => $sig->gross_rr !== null ? (string) $sig->gross_rr : null,
                'net_rr' => $sig->net_rr !== null ? (string) $sig->net_rr : null,
                'entry_stages' => $sig->entry_stages ?? [],
                'rationale' => $sig->rationale ?? [],
                'invalidation_condition' => $sig->invalidation_condition,
                'safeguard_suppressed' => (bool) $sig->safeguard_suppressed,
                'calculation_version' => $sig->calculation_version,
                'is_demo' => (bool) $sig->is_demo,
            ] : null;
        }

        $signalHistory = $instrument->tradingSignals
            ->where('horizon', 'swing')
            ->take(8)
            ->map(fn ($s): array => [
                'id' => $s->id,
                'signal_date' => $s->signal_date->toDateString(),
                'stance' => $s->stance,
                'entry_low' => $s->entry_low !== null ? (string) $s->entry_low : null,
                'stop_loss' => $s->stop_loss !== null ? (string) $s->stop_loss : null,
                'target_1' => $s->target_1 !== null ? (string) $s->target_1 : null,
                'net_rr' => $s->net_rr !== null ? (string) $s->net_rr : null,
                'safeguard_suppressed' => (bool) $s->safeguard_suppressed,
            ])
            ->values()
            ->all();

        $exCode = strtoupper($exchangeModel->code);
        $tradingCosts = config("nadi.trading_costs.{$exCode}") ?? config('nadi.trading_costs.DEFAULT');
        $holdingStatus = $valuation->holdingStatusForInstrument($request->user(), $instrument);
        $relatedNews = $newsroom->forInstrument($instrument, 5);
        $researchNotes = $research->forInstrument($request->user(), $instrument, 5);

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
            'indicatorSeries' => $indicatorSeries,
            'benchmarkOverlay' => $benchmarkOverlay,
            'fundamentals' => $fund ? [
                'as_of_date' => $fund->as_of_date->toDateString(),
                'period_label' => $fund->period_label,
                'statement_currency' => $fund->statement_currency,
                'market_cap' => $fund->market_cap !== null ? (string) $fund->market_cap : null,
                'market_cap_idr' => $fund->market_cap_idr !== null ? (string) $fund->market_cap_idr : null,
                'eps' => $fund->eps !== null ? (string) $fund->eps : null,
                'per' => $fund->per !== null ? (string) $fund->per : null,
                'pbv' => $fund->pbv !== null ? (string) $fund->pbv : null,
                'roe' => $fund->roe !== null ? (string) $fund->roe : null,
                'roa' => $fund->roa !== null ? (string) $fund->roa : null,
                'der' => $fund->der !== null ? (string) $fund->der : null,
                'current_ratio' => $fund->current_ratio !== null ? (string) $fund->current_ratio : null,
                'net_margin' => $fund->net_margin !== null ? (string) $fund->net_margin : null,
                'revenue_growth_yoy' => $fund->revenue_growth_yoy !== null ? (string) $fund->revenue_growth_yoy : null,
                'earnings_growth_yoy' => $fund->earnings_growth_yoy !== null ? (string) $fund->earnings_growth_yoy : null,
                'ocf' => $fund->ocf !== null ? (string) $fund->ocf : null,
                'fcf' => $fund->fcf !== null ? (string) $fund->fcf : null,
                'dividend_yield' => $fund->dividend_yield !== null ? (string) $fund->dividend_yield : null,
                'dividend_payout_ratio' => $fund->dividend_payout_ratio !== null ? (string) $fund->dividend_payout_ratio : null,
                'is_financial_sector' => (bool) $fund->is_financial_sector,
                'missing_fields' => $fund->missing_fields ?? [],
                'is_demo' => (bool) $fund->is_demo,
                'source' => $fund->dataSource?->name ?? 'Demo',
            ] : null,
            'statements' => $instrument->financialStatements->take(4)->map(fn ($s): array => [
                'period_label' => "{$s->period_type} {$s->fiscal_year}",
                'period_end_date' => $s->period_end_date->toDateString(),
                'published_at' => $s->published_at->toDateString(),
                'currency' => $s->currency,
                'revenue' => $s->revenue !== null ? (string) $s->revenue : null,
                'net_income' => $s->net_income !== null ? (string) $s->net_income : null,
                'total_assets' => $s->total_assets !== null ? (string) $s->total_assets : null,
                'total_equity' => $s->total_equity !== null ? (string) $s->total_equity : null,
                'operating_cash_flow' => $s->operating_cash_flow !== null ? (string) $s->operating_cash_flow : null,
                'free_cash_flow' => $s->free_cash_flow !== null ? (string) $s->free_cash_flow : null,
                'is_demo' => (bool) $s->is_demo,
            ])->values()->all(),
            'technicals' => $tech ? [
                'trade_date' => $tech->trade_date->toDateString(),
                'sma_20' => $tech->sma_20 !== null ? (string) $tech->sma_20 : null,
                'sma_50' => $tech->sma_50 !== null ? (string) $tech->sma_50 : null,
                'sma_200' => $tech->sma_200 !== null ? (string) $tech->sma_200 : null,
                'ema_20' => $tech->ema_20 !== null ? (string) $tech->ema_20 : null,
                'rsi_14' => $tech->rsi_14 !== null ? (string) $tech->rsi_14 : null,
                'macd_line' => $tech->macd_line !== null ? (string) $tech->macd_line : null,
                'macd_signal' => $tech->macd_signal !== null ? (string) $tech->macd_signal : null,
                'macd_hist' => $tech->macd_hist !== null ? (string) $tech->macd_hist : null,
                'atr_14' => $tech->atr_14 !== null ? (string) $tech->atr_14 : null,
                'obv' => $tech->obv,
                'volatility_20d' => $tech->volatility_20d !== null ? (string) $tech->volatility_20d : null,
                'relative_strength_1m' => $tech->relative_strength_1m !== null ? (string) $tech->relative_strength_1m : null,
            ] : null,
            'score' => $score ? [
                'as_of_date' => $score->as_of_date->toDateString(),
                'overall_score' => (string) $score->overall_score,
                'fundamentals_score' => $score->fundamentals_score !== null ? (string) $score->fundamentals_score : null,
                'valuation_score' => $score->valuation_score !== null ? (string) $score->valuation_score : null,
                'technical_score' => $score->technical_score !== null ? (string) $score->technical_score : null,
                'risk_score' => $score->risk_score !== null ? (string) $score->risk_score : null,
                'catalysts_score' => $score->catalysts_score !== null ? (string) $score->catalysts_score : null,
                'sector_percentile' => $score->sector_percentile !== null ? (string) $score->sector_percentile : null,
                'data_coverage' => (string) $score->data_coverage,
                'reasons' => $score->reasons ?? [],
                'missing_indicators' => $score->missing_indicators ?? [],
                'weights_used' => $score->weights_used ?? [],
                'calculation_version' => $score->calculation_version,
                'is_demo' => (bool) $score->is_demo,
            ] : null,
            'scoreHistory' => $instrument->scoreHistory->map(fn ($h): array => [
                'date' => $h->score_date->toDateString(),
                'overall_score' => (string) $h->overall_score,
            ])->values()->all(),
            'corporateActions' => $instrument->corporateActions->map(fn ($a): array => [
                'id' => $a->id,
                'action_type' => $a->action_type,
                'ex_date' => $a->ex_date->toDateString(),
                'payment_date' => $a->payment_date?->toDateString(),
                'declared_currency' => $a->declared_currency,
                'cash_amount' => $a->cash_amount !== null ? (string) $a->cash_amount : null,
                'ratio_numerator' => $a->ratio_numerator !== null ? (string) $a->ratio_numerator : null,
                'ratio_denominator' => $a->ratio_denominator !== null ? (string) $a->ratio_denominator : null,
                'notes' => $a->notes,
                'is_demo' => (bool) $a->is_demo,
            ])->values()->all(),
            'safety' => $safety ? [
                'as_of_date' => $safety->as_of_date->toDateString(),
                'risk_score' => $safety->risk_score !== null ? (string) $safety->risk_score : null,
                'risk_category' => $safety->risk_category,
                'safeguard_triggered' => (bool) $safety->safeguard_triggered,
                'triggered_rules' => $safety->triggered_rules ?? [],
                'data_confidence' => (string) $safety->data_confidence,
                'missing_factors' => $safety->missing_factors ?? [],
                'calculation_version' => $safety->calculation_version,
                'is_demo' => (bool) $safety->is_demo,
                'regulatory' => $regulatory ? [
                    'is_suspended' => (bool) $regulatory->is_suspended,
                    'has_exchange_warning' => (bool) $regulatory->has_exchange_warning,
                    'has_special_monitoring' => (bool) $regulatory->has_special_monitoring,
                    'free_float_pct' => $regulatory->free_float_pct !== null ? (string) $regulatory->free_float_pct : null,
                    'top_holder_pct' => $regulatory->top_holder_pct !== null ? (string) $regulatory->top_holder_pct : null,
                    'status_notes' => $regulatory->status_notes,
                    'source_note' => $regulatory->source_note,
                ] : null,
            ] : null,
            'safetyHistory' => $instrument->safetyHistory->map(fn ($h): array => [
                'date' => $h->assessment_date->toDateString(),
                'risk_score' => $h->risk_score !== null ? (string) $h->risk_score : null,
                'risk_category' => $h->risk_category,
            ])->values()->all(),
            'signalsByHorizon' => $signalsByHorizon,
            'signalHistory' => $signalHistory,
            'tradingCosts' => $tradingCosts,
            'holdingStatus' => $holdingStatus,
            'relatedNews' => $relatedNews,
            'researchNotes' => $researchNotes,
            'inWatchlist' => $inWatchlist,
        ]);
    }
}
