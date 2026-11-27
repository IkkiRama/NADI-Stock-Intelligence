<?php

namespace App\Http\Controllers;

use App\Domain\StrategyLab\Services\StrategyBacktestService;
use App\Models\Instrument;
use App\Models\StrategyBacktestRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StrategyLabController extends Controller
{
    public function index(Request $request, StrategyBacktestService $service): Response
    {
        $instruments = Instrument::query()
            ->with('exchange:id,code')
            ->where('is_active', true)
            ->orderBy('symbol')
            ->get(['id', 'exchange_id', 'symbol', 'name', 'currency', 'lot_size']);

        $instrument = $this->resolveInstrument($request, $instruments);
        $options = $this->extractOptions($request);

        $backtest = $instrument ? $service->runBacktest($instrument, $options) : null;

        $savedRuns = StrategyBacktestRun::query()
            ->with('instrument.exchange:id,code')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('executed_at')
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(fn (StrategyBacktestRun $r): array => [
                'id' => $r->id,
                'name' => $r->name,
                'symbol' => $r->instrument?->symbol ?? '',
                'exchange_code' => $r->instrument?->exchange?->code ?? '',
                'strategy_code' => $r->strategy_code,
                'range_code' => $r->range_code,
                'currency' => $r->currency,
                'initial_capital' => (string) $r->initial_capital,
                'parameters' => $r->parameters ?? [],
                'trades_count' => (int) $r->trades_count,
                'total_return_pct' => $r->total_return_pct !== null ? (string) $r->total_return_pct : null,
                'buy_hold_return_pct' => $r->buy_hold_return_pct !== null ? (string) $r->buy_hold_return_pct : null,
                'max_drawdown_pct' => $r->max_drawdown_pct !== null ? (string) $r->max_drawdown_pct : null,
                'sharpe_ratio' => $r->sharpe_ratio !== null ? (string) $r->sharpe_ratio : null,
                'win_rate_pct' => $r->win_rate_pct !== null ? (string) $r->win_rate_pct : null,
                'is_demo' => (bool) $r->is_demo,
                'executed_at' => $r->executed_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('strategy-lab/index', [
            'backtest' => $backtest,
            'strategies' => StrategyBacktestService::STRATEGIES,
            'ranges' => StrategyBacktestService::VALID_RANGES,
            'instruments' => $instruments->map(fn (Instrument $i): array => [
                'id' => $i->id,
                'exchange_code' => $i->exchange?->code ?? 'IDX',
                'symbol' => $i->symbol,
                'name' => $i->name,
                'currency' => $i->currency,
                'lot_size' => (int) $i->lot_size,
            ])->values()->all(),
            'filters' => array_merge([
                'symbol' => $instrument?->symbol ?? 'BBCA',
                'strategy' => $backtest['strategy'] ?? 'sma_crossover',
                'range' => $backtest['range'] ?? '1Y',
            ], $backtest['parameters'] ?? []),
            'savedRuns' => $savedRuns,
        ]);
    }

    public function storeRun(Request $request, StrategyBacktestService $service): RedirectResponse
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:24'],
            'name' => ['nullable', 'string', 'max:120'],
            'strategy' => ['required', 'string', Rule::in(StrategyBacktestService::STRATEGIES)],
            'range' => ['required', 'string', Rule::in(StrategyBacktestService::VALID_RANGES)],
            'initial_capital' => ['nullable', 'numeric', 'min:1'],
            'allocation_pct' => ['nullable', 'numeric', 'min:0.1', 'max:1'],
            'buy_fee_pct' => ['nullable', 'numeric', 'min:0', 'max:0.05'],
            'sell_fee_pct' => ['nullable', 'numeric', 'min:0', 'max:0.05'],
            'slippage_pct' => ['nullable', 'numeric', 'min:0', 'max:0.05'],
            'stop_loss_pct' => ['nullable', 'numeric', 'min:0.01', 'max:0.5'],
            'take_profit_pct' => ['nullable', 'numeric', 'min:0.02', 'max:2'],
            'fast_period' => ['nullable', 'integer', 'min:5', 'max:100'],
            'slow_period' => ['nullable', 'integer', 'min:10', 'max:200'],
            'rsi_period' => ['nullable', 'integer', 'min:5', 'max:30'],
            'oversold' => ['nullable', 'integer', 'min:15', 'max:45'],
            'overbought' => ['nullable', 'integer', 'min:55', 'max:85'],
            'fast_ema' => ['nullable', 'integer', 'min:5', 'max:30'],
            'slow_ema' => ['nullable', 'integer', 'min:10', 'max:60'],
            'signal_ema' => ['nullable', 'integer', 'min:3', 'max:20'],
            'entry_lookback' => ['nullable', 'integer', 'min:10', 'max:100'],
            'exit_lookback' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $instrument = Instrument::query()
            ->with('exchange:id,code')
            ->where('symbol', strtoupper($validated['symbol']))
            ->where('is_active', true)
            ->firstOrFail();

        $result = $service->runBacktest($instrument, $validated);
        $s = $result['summary'];

        StrategyBacktestRun::query()->create([
            'user_id' => $request->user()->id,
            'instrument_id' => $instrument->id,
            'name' => $validated['name'] ?? null,
            'strategy_code' => $result['strategy'],
            'range_code' => $result['range'],
            'currency' => $result['instrument']['currency'],
            'initial_capital' => $s['initial_capital'],
            'parameters' => $result['parameters'],
            'summary_metrics' => $s,
            'trades_count' => $s['closed_trades_count'],
            'total_return_pct' => $s['total_return_pct'],
            'buy_hold_return_pct' => $s['buy_hold_return_pct'],
            'max_drawdown_pct' => $s['max_drawdown_pct'],
            'sharpe_ratio' => $s['sharpe_ratio'],
            'win_rate_pct' => $s['win_rate_pct'],
            'is_demo' => (bool) $result['is_demo'],
            'executed_at' => now('UTC'),
        ]);

        return back()->with('status', 'backtest-saved');
    }

    public function destroyRun(Request $request, StrategyBacktestRun $strategyBacktestRun): RedirectResponse
    {
        abort_if($strategyBacktestRun->user_id !== $request->user()->id, 403);

        $strategyBacktestRun->delete();

        return back()->with('status', 'backtest-deleted');
    }

    public function exportCsv(Request $request, StrategyBacktestService $service): StreamedResponse
    {
        $instruments = Instrument::query()
            ->with('exchange:id,code')
            ->where('is_active', true)
            ->orderBy('symbol')
            ->get(['id', 'exchange_id', 'symbol', 'name', 'currency', 'lot_size']);

        $instrument = $this->resolveInstrument($request, $instruments);
        abort_if(! $instrument, 404, 'No active instrument found for Strategy Lab export.');

        $result = $service->runBacktest($instrument, $this->extractOptions($request));
        $csv = $service->exportBacktestCsv($result);
        $symbol = strtolower($instrument->symbol);
        $strategy = strtolower($result['strategy']);
        $filename = "nadi-backtest-{$symbol}-{$strategy}-".now('UTC')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Instrument>  $instruments
     */
    private function resolveInstrument(Request $request, $instruments): ?Instrument
    {
        $symbol = strtoupper(trim((string) $request->query('symbol', 'BBCA')));
        $found = $instruments->first(fn (Instrument $i) => strtoupper($i->symbol) === $symbol);

        return $found ?: $instruments->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function extractOptions(Request $request): array
    {
        return array_filter([
            'strategy' => $request->query('strategy', 'sma_crossover'),
            'range' => $request->query('range', '1Y'),
            'initial_capital' => $request->query('initial_capital'),
            'allocation_pct' => $request->query('allocation_pct'),
            'buy_fee_pct' => $request->query('buy_fee_pct'),
            'sell_fee_pct' => $request->query('sell_fee_pct'),
            'slippage_pct' => $request->query('slippage_pct'),
            'stop_loss_pct' => $request->query('stop_loss_pct'),
            'take_profit_pct' => $request->query('take_profit_pct'),
            'fast_period' => $request->query('fast_period'),
            'slow_period' => $request->query('slow_period'),
            'rsi_period' => $request->query('rsi_period'),
            'oversold' => $request->query('oversold'),
            'overbought' => $request->query('overbought'),
            'fast_ema' => $request->query('fast_ema'),
            'slow_ema' => $request->query('slow_ema'),
            'signal_ema' => $request->query('signal_ema'),
            'entry_lookback' => $request->query('entry_lookback'),
            'exit_lookback' => $request->query('exit_lookback'),
        ], fn ($v) => $v !== null && $v !== '');
    }
}
