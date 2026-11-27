<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\Services\TechnicalIndicatorService;
use App\Domain\TradingSignals\Services\PositionSizingCalculator;
use App\Domain\TradingSignals\Services\SmartEntryExitService;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class EntryExitController extends Controller
{
    public function index(Request $request, TechnicalIndicatorService $technicalsService): Response
    {
        $horizon = (string) $request->query('horizon', 'swing');
        if (! in_array($horizon, SmartEntryExitService::HORIZONS, true)) {
            $horizon = 'swing';
        }

        $requestedSymbol = strtoupper(trim((string) $request->query('symbol', 'BBCA')));
        $requestedExchange = strtoupper(trim((string) $request->query('exchange', '')));

        $instrumentsList = Instrument::query()
            ->join('exchanges', 'exchanges.id', '=', 'instruments.exchange_id')
            ->where('instruments.is_active', true)
            ->orderBy('exchanges.id')
            ->orderBy('instruments.symbol')
            ->get([
                'instruments.id',
                'instruments.symbol',
                'instruments.name',
                'instruments.currency',
                'exchanges.code as exchange_code',
            ])
            ->map(fn ($r): array => [
                'id' => (int) $r->id,
                'symbol' => $r->symbol,
                'name' => $r->name,
                'currency' => $r->currency,
                'exchange_code' => $r->exchange_code,
            ])
            ->values()
            ->all();

        $instQuery = Instrument::query()
            ->with([
                'exchange:id,code,name,default_lot_size',
                'latestQuote',
                'latestScore',
                'safetyAssessment',
                'regulatoryStatus',
                'tradingSignals',
            ])
            ->where('is_active', true);

        if ($requestedSymbol !== '') {
            $instQuery->whereRaw('LOWER(symbol) = ?', [strtolower($requestedSymbol)]);
        }
        if ($requestedExchange !== '') {
            $instQuery->whereHas('exchange', fn ($q) => $q->whereRaw('LOWER(code) = ?', [strtolower($requestedExchange)]));
        }

        $instrument = $instQuery->first()
            ?? Instrument::query()->with(['exchange:id,code,name,default_lot_size', 'latestQuote', 'latestScore', 'safetyAssessment', 'regulatoryStatus', 'tradingSignals'])->where('is_active', true)->first();

        $bars = [];
        $indicatorSeries = [];
        $signalsByHorizon = [];
        $signalHistory = [];
        $tradingCosts = config('nadi.trading_costs.DEFAULT');

        if ($instrument) {
            $barsCollection = InstrumentPriceDaily::query()
                ->where('instrument_id', $instrument->id)
                ->orderByDesc('trade_date')
                ->limit(180)
                ->get(['trade_date', 'open', 'high', 'low', 'close', 'volume'])
                ->reverse()
                ->values();

            foreach ($barsCollection as $bar) {
                $bars[] = [
                    'date' => $bar->trade_date->toDateString(),
                    'open' => (string) $bar->open,
                    'high' => (string) $bar->high,
                    'low' => (string) $bar->low,
                    'close' => (string) $bar->close,
                    'volume' => (int) $bar->volume,
                ];
            }

            $indicatorSeries = $technicalsService->computeSeries($bars);

            foreach (SmartEntryExitService::HORIZONS as $h) {
                $sig = $instrument->tradingSignals->where('horizon', $h)->firstWhere('is_latest', true);
                $signalsByHorizon[$h] = $sig ? $this->serializeSignal($sig) : null;
            }

            $signalHistory = $instrument->tradingSignals
                ->where('horizon', $horizon)
                ->take(10)
                ->map(fn ($s): array => $this->serializeSignal($s))
                ->values()
                ->all();

            $exCode = strtoupper($instrument->exchange->code);
            $tradingCosts = config("nadi.trading_costs.{$exCode}") ?? config('nadi.trading_costs.DEFAULT');
        }

        $safety = $instrument?->safetyAssessment;

        return Inertia::render('intelligence/entry-exit', [
            'instruments' => $instrumentsList,
            'selectedHorizon' => $horizon,
            'instrument' => $instrument ? [
                'id' => $instrument->id,
                'symbol' => $instrument->symbol,
                'name' => $instrument->name,
                'currency' => $instrument->currency,
                'exchange_code' => $instrument->exchange->code,
                'lot_size' => $instrument->lot_size ?? $instrument->exchange->default_lot_size,
                'close' => $instrument->latestQuote ? (string) $instrument->latestQuote->close : null,
                'change_pct' => $instrument->latestQuote?->change_pct !== null ? (string) $instrument->latestQuote->change_pct : null,
                'as_of' => $instrument->latestQuote?->trade_date?->toDateString(),
                'overall_score' => $instrument->latestScore ? (string) $instrument->latestScore->overall_score : null,
                'is_demo' => (bool) ($instrument->latestQuote?->is_demo ?? false),
            ] : null,
            'bars' => $bars,
            'indicatorSeries' => $indicatorSeries,
            'safety' => $safety ? [
                'risk_score' => $safety->risk_score !== null ? (string) $safety->risk_score : null,
                'risk_category' => $safety->risk_category,
                'safeguard_triggered' => (bool) $safety->safeguard_triggered,
                'triggered_rules' => $safety->triggered_rules ?? [],
                'data_confidence' => (string) $safety->data_confidence,
            ] : null,
            'signalsByHorizon' => $signalsByHorizon,
            'signalHistory' => $signalHistory,
            'tradingCosts' => $tradingCosts,
        ]);
    }

    public function calculatePositionSize(Request $request, PositionSizingCalculator $calculator): JsonResponse
    {
        $validated = $request->validate([
            'capital' => ['required', 'numeric', 'gt:0'],
            'max_risk_pct' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'entry_price' => ['required', 'numeric', 'gt:0'],
            'stop_loss' => ['required', 'numeric', 'gt:0'],
            'target_price' => ['nullable', 'numeric', 'gt:0'],
            'lot_size' => ['required', 'integer', 'min:1', 'max:100000'],
            'buy_fee_pct' => ['nullable', 'numeric', 'min:0', 'max:0.1'],
            'sell_fee_pct' => ['nullable', 'numeric', 'min:0', 'max:0.1'],
            'slippage_pct' => ['nullable', 'numeric', 'min:0', 'max:0.1'],
        ]);

        try {
            $result = $calculator->calculate(
                (string) $validated['capital'],
                (string) $validated['max_risk_pct'],
                (string) $validated['entry_price'],
                (string) $validated['stop_loss'],
                isset($validated['target_price']) && $validated['target_price'] !== null ? (string) $validated['target_price'] : null,
                (int) $validated['lot_size'],
                (string) ($validated['buy_fee_pct'] ?? '0'),
                (string) ($validated['sell_fee_pct'] ?? '0'),
                (string) ($validated['slippage_pct'] ?? '0'),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['stop_loss' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSignal($s): array
    {
        return [
            'id' => $s->id,
            'horizon' => $s->horizon,
            'signal_date' => $s->signal_date->toDateString(),
            'is_latest' => (bool) $s->is_latest,
            'stance' => $s->stance,
            'entry_low' => $s->entry_low !== null ? (string) $s->entry_low : null,
            'entry_high' => $s->entry_high !== null ? (string) $s->entry_high : null,
            'confirmation_price' => $s->confirmation_price !== null ? (string) $s->confirmation_price : null,
            'stop_loss' => $s->stop_loss !== null ? (string) $s->stop_loss : null,
            'invalidation_price' => $s->invalidation_price !== null ? (string) $s->invalidation_price : null,
            'target_1' => $s->target_1 !== null ? (string) $s->target_1 : null,
            'target_2' => $s->target_2 !== null ? (string) $s->target_2 : null,
            'trailing_stop_atr_mult' => $s->trailing_stop_atr_mult !== null ? (string) $s->trailing_stop_atr_mult : null,
            'support_level' => $s->support_level !== null ? (string) $s->support_level : null,
            'resistance_level' => $s->resistance_level !== null ? (string) $s->resistance_level : null,
            'gross_rr' => $s->gross_rr !== null ? (string) $s->gross_rr : null,
            'net_rr' => $s->net_rr !== null ? (string) $s->net_rr : null,
            'entry_stages' => $s->entry_stages ?? [],
            'rationale' => $s->rationale ?? [],
            'invalidation_condition' => $s->invalidation_condition,
            'safeguard_suppressed' => (bool) $s->safeguard_suppressed,
            'calculation_version' => $s->calculation_version,
            'is_demo' => (bool) $s->is_demo,
        ];
    }
}
