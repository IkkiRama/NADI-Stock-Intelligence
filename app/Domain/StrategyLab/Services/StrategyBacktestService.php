<?php

namespace App\Domain\StrategyLab\Services;

use App\Domain\Analytics\Services\TechnicalIndicatorService;
use App\Domain\Instruments\Support\TickSize;
use App\Domain\MarketData\Services\CsvSanitizer;
use App\Models\Instrument;
use App\Models\InstrumentPriceDaily;
use App\Support\Math\Dec;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Deterministic Daily-Bar Strategy Backtesting Engine.
 *
 * Safeguards:
 * - Zero look-ahead bias: signals detected at bar t close execute strictly at bar t+1 open.
 * - Conservative intrabar SL/TP tie-breaking: Stop-Loss precedes Take-Profit on same-bar hits.
 * - Exchange tick rounding (TickSize), whole-lot sizing (RoundingMode::DOWN), fees & slippage.
 */
final class StrategyBacktestService
{
    public const STRATEGIES = [
        'sma_crossover',
        'rsi_mean_reversion',
        'macd_momentum',
        'donchian_breakout',
    ];

    public const VALID_RANGES = ['6M', '1Y', 'ALL'];

    public function __construct(
        private readonly TechnicalIndicatorService $indicators,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function runBacktest(Instrument $instrument, array $options = []): array
    {
        $instrument->loadMissing(['exchange:id,code,name', 'latestQuote:instrument_id,close,change_pct,is_demo']);

        $allBarsQuery = InstrumentPriceDaily::query()
            ->where('instrument_id', $instrument->id)
            ->orderByDesc('trade_date')
            ->limit(600)
            ->get(['trade_date', 'open', 'high', 'low', 'close', 'volume', 'is_demo'])
            ->reverse()
            ->values();

        $bars = [];
        foreach ($allBarsQuery as $b) {
            $bars[] = [
                'date' => $b->trade_date->toDateString(),
                'open' => (string) $b->open,
                'high' => (string) $b->high,
                'low' => (string) $b->low,
                'close' => (string) $b->close,
                'volume' => (int) $b->volume,
                'is_demo' => (bool) $b->is_demo,
            ];
        }

        $range = strtoupper((string) ($options['range'] ?? '1Y'));
        if (! in_array($range, self::VALID_RANGES, true)) {
            $range = '1Y';
        }

        $evalStartIdx = 0;
        if (! empty($bars) && $range !== 'ALL') {
            $lastDate = CarbonImmutable::parse($bars[count($bars) - 1]['date'], 'UTC');
            $cutoff = match ($range) {
                '6M' => $lastDate->subMonths(6)->toDateString(),
                default => $lastDate->subYear()->toDateString(),
            };
            foreach ($bars as $idx => $bar) {
                if ($bar['date'] >= $cutoff) {
                    $evalStartIdx = $idx;
                    break;
                }
            }
        }

        return $this->simulateOnBars($instrument, $bars, array_merge($options, ['range' => $range]), $evalStartIdx);
    }

    /**
     * @param  list<array{date: string, open: string|float, high: string|float, low: string|float, close: string|float, volume?: int, is_demo?: bool}>  $bars
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function simulateOnBars(Instrument $instrument, array $bars, array $options = [], int $evalStartIdx = 0): array
    {
        $instrument->loadMissing(['exchange:id,code,name']);
        $exchangeCode = strtoupper($instrument->exchange?->code ?? 'IDX');
        $currency = strtoupper($instrument->currency ?: 'IDR');
        $lotSize = max(1, (int) ($instrument->lot_size ?: ($exchangeCode === 'IDX' ? 100 : 1)));

        $strategy = strtolower((string) ($options['strategy'] ?? 'sma_crossover'));
        if (! in_array($strategy, self::STRATEGIES, true)) {
            $strategy = 'sma_crossover';
        }

        $range = strtoupper((string) ($options['range'] ?? '1Y'));
        if (! in_array($range, self::VALID_RANGES, true)) {
            $range = '1Y';
        }

        $params = $this->normalizeStrategyParameters($strategy, $options);
        $tradingCostDefaults = config("nadi.trading_costs.{$exchangeCode}", config('nadi.trading_costs.DEFAULT'));

        $defaultCapital = $currency === 'IDR' ? '100000000.0000' : '10000.0000';
        $rawCap = $options['initial_capital'] ?? null;
        $initialCapital = ($rawCap !== null && is_numeric((string) $rawCap) && Dec::cmp((string) $rawCap, '0') > 0)
            ? Dec::round((string) $rawCap, 4)
            : $defaultCapital;

        $allocationPct = $this->clampFraction($options['allocation_pct'] ?? '0.95', '0.10', '1.00', '0.95', 4);
        $buyFeePct = $this->clampFraction($options['buy_fee_pct'] ?? $tradingCostDefaults['buy_fee_pct'], '0', '0.05', $tradingCostDefaults['buy_fee_pct'], 6);
        $sellFeePct = $this->clampFraction($options['sell_fee_pct'] ?? $tradingCostDefaults['sell_fee_pct'], '0', '0.05', $tradingCostDefaults['sell_fee_pct'], 6);
        $slippagePct = $this->clampFraction($options['slippage_pct'] ?? $tradingCostDefaults['slippage_pct'], '0', '0.05', $tradingCostDefaults['slippage_pct'], 6);

        $stopLossPct = $this->parseOptionalFraction($options['stop_loss_pct'] ?? null, '0.01', '0.50', 4);
        $takeProfitPct = $this->parseOptionalFraction($options['take_profit_pct'] ?? null, '0.02', '2.00', 4);

        $n = count($bars);
        $evalStartIdx = max(0, min($evalStartIdx, max(0, $n - 1)));
        $isDemo = false;
        foreach ($bars as $b) {
            if (! empty($b['is_demo'])) {
                $isDemo = true;
                break;
            }
        }

        if ($n - $evalStartIdx < 3) {
            return $this->emptyResult($instrument, $strategy, $range, $currency, $lotSize, $initialCapital, array_merge($params, [
                'allocation_pct' => $allocationPct,
                'buy_fee_pct' => $buyFeePct,
                'sell_fee_pct' => $sellFeePct,
                'slippage_pct' => $slippagePct,
                'stop_loss_pct' => $stopLossPct,
                'take_profit_pct' => $takeProfitPct,
            ]), $isDemo);
        }

        $signals = $this->computeSignals($bars, $strategy, $params);

        // Buy-and-Hold baseline computed on bar $evalStartIdx open (strictly no fee/lot fantasy)
        $bhOpenRaw = Dec::mul((string) $bars[$evalStartIdx]['open'], Dec::add('1', $slippagePct, 6), 6);
        $bhExecPx = TickSize::roundToTick($exchangeCode, $bhOpenRaw, 6);
        $bhShares = 0;
        $bhCash = $initialCapital;
        $bhLotCost = Dec::mul(Dec::mul($bhExecPx, (string) $lotSize, 6), Dec::add('1', $buyFeePct, 6), 6);
        if (Dec::cmp($bhLotCost, '0') > 0 && Dec::cmp($initialCapital, $bhLotCost) >= 0) {
            $bhLots = (int) Dec::of($initialCapital)->dividedBy(Dec::of($bhLotCost), 0, RoundingMode::DOWN)->toInt();
            while ($bhLots > 0) {
                $s = $bhLots * $lotSize;
                $g = Dec::mul($bhExecPx, (string) $s, 4);
                $f = Dec::mul($g, $buyFeePct, 4);
                $tot = Dec::add($g, $f, 4);
                if (Dec::cmp($tot, $initialCapital) <= 0) {
                    $bhShares = $s;
                    $bhCash = Dec::sub($initialCapital, $tot, 4);
                    break;
                }
                $bhLots--;
            }
        }

        $cash = $initialCapital;
        $position = null;
        $pendingOrder = null;
        $trades = [];
        $equityCurve = [];
        $peakEquity = $initialCapital;
        $maxDrawdown = '0.00000000';
        $totalFeesPaid = '0.0000';
        $daysInMarket = 0;
        $tradeCounter = 0;

        for ($i = $evalStartIdx; $i < $n; $i++) {
            $bar = $bars[$i];
            $date = $bar['date'];
            $open = (string) $bar['open'];
            $high = (string) $bar['high'];
            $low = (string) $bar['low'];
            $close = (string) $bar['close'];

            // Step A: Execute pending signal from bar i-1 strictly at bar i open
            if ($pendingOrder !== null) {
                if ($pendingOrder['type'] === 'BUY' && $position === null) {
                    $rawPx = Dec::mul($open, Dec::add('1', $slippagePct, 6), 6);
                    $execPx = TickSize::roundToTick($exchangeCode, $rawPx, 6);
                    $budget = Dec::mul($cash, $allocationPct, 4);
                    $costPerLot = Dec::mul(Dec::mul($execPx, (string) $lotSize, 6), Dec::add('1', $buyFeePct, 6), 6);

                    if (Dec::cmp($costPerLot, '0') > 0 && Dec::cmp($budget, $costPerLot) >= 0) {
                        $lots = (int) Dec::of($budget)->dividedBy(Dec::of($costPerLot), 0, RoundingMode::DOWN)->toInt();
                        while ($lots > 0) {
                            $shares = $lots * $lotSize;
                            $gross = Dec::mul($execPx, (string) $shares, 4);
                            $fee = Dec::mul($gross, $buyFeePct, 4);
                            $totalOut = Dec::add($gross, $fee, 4);
                            if (Dec::cmp($totalOut, $cash) <= 0) {
                                $cash = Dec::sub($cash, $totalOut, 4);
                                $totalFeesPaid = Dec::add($totalFeesPaid, $fee, 4);

                                $stopPx = $stopLossPct !== null
                                    ? TickSize::roundToTick($exchangeCode, Dec::mul($execPx, Dec::sub('1', $stopLossPct, 6), 6), 6)
                                    : null;
                                $tpPx = $takeProfitPct !== null
                                    ? TickSize::roundToTick($exchangeCode, Dec::mul($execPx, Dec::add('1', $takeProfitPct, 6), 6), 6)
                                    : null;

                                $position = [
                                    'signal_date' => $pendingOrder['signal_date'],
                                    'entry_date' => $date,
                                    'entry_bar_idx' => $i,
                                    'shares' => $shares,
                                    'entry_price' => $execPx,
                                    'entry_gross' => $gross,
                                    'entry_fee' => $fee,
                                    'stop_price' => $stopPx,
                                    'tp_price' => $tpPx,
                                ];
                                break;
                            }
                            $lots--;
                        }
                    }
                } elseif ($pendingOrder['type'] === 'SELL' && $position !== null) {
                    $rawPx = Dec::mul($open, Dec::sub('1', $slippagePct, 6), 6);
                    $execPx = TickSize::roundToTick($exchangeCode, $rawPx, 6);
                    $trade = $this->closePosition($position, $date, $execPx, $sellFeePct, $pendingOrder['reason'], ++$tradeCounter);
                    $cash = Dec::add($cash, $trade['net_proceeds'], 4);
                    $totalFeesPaid = Dec::add($totalFeesPaid, $trade['exit_fee'], 4);
                    $trades[] = $trade;
                    $position = null;
                }

                $pendingOrder = null;
            }

            // Step B: Intrabar SL / TP protection on bars strictly after entry bar (Stop-Loss takes precedence!)
            if ($position !== null && $i > $position['entry_bar_idx']) {
                $stopPx = $position['stop_price'];
                $tpPx = $position['tp_price'];

                if ($stopPx !== null && Dec::cmp($low, $stopPx) <= 0) {
                    $triggerRef = Dec::cmp($open, $stopPx) <= 0 ? $open : $stopPx;
                    $rawPx = Dec::mul($triggerRef, Dec::sub('1', $slippagePct, 6), 6);
                    $execPx = TickSize::roundToTick($exchangeCode, $rawPx, 6);
                    $trade = $this->closePosition($position, $date, $execPx, $sellFeePct, 'stop_loss', ++$tradeCounter);
                    $cash = Dec::add($cash, $trade['net_proceeds'], 4);
                    $totalFeesPaid = Dec::add($totalFeesPaid, $trade['exit_fee'], 4);
                    $trades[] = $trade;
                    $position = null;
                } elseif ($tpPx !== null && Dec::cmp($high, $tpPx) >= 0) {
                    $triggerRef = Dec::cmp($open, $tpPx) >= 0 ? $open : $tpPx;
                    $rawPx = Dec::mul($triggerRef, Dec::sub('1', $slippagePct, 6), 6);
                    $execPx = TickSize::roundToTick($exchangeCode, $rawPx, 6);
                    $trade = $this->closePosition($position, $date, $execPx, $sellFeePct, 'take_profit', ++$tradeCounter);
                    $cash = Dec::add($cash, $trade['net_proceeds'], 4);
                    $totalFeesPaid = Dec::add($totalFeesPaid, $trade['exit_fee'], 4);
                    $trades[] = $trade;
                    $position = null;
                }
            }

            // Step C: Mark-to-Market daily equity & drawdown
            if ($position !== null) {
                $daysInMarket++;
            }
            $posValue = $position !== null ? Dec::mul((string) $position['shares'], $close, 4) : '0.0000';
            $equity = Dec::add($cash, $posValue, 4);
            $bhEquity = Dec::add($bhCash, Dec::mul((string) $bhShares, $close, 4), 4);

            if (Dec::cmp($equity, $peakEquity) > 0) {
                $peakEquity = $equity;
            }
            $dd = Dec::cmp($peakEquity, '0') > 0
                ? Dec::pctChange($equity, $peakEquity, 8) ?? '0.00000000'
                : '0.00000000';
            if (Dec::cmp($dd, $maxDrawdown) < 0) {
                $maxDrawdown = $dd;
            }

            $equityCurve[] = [
                'date' => $date,
                'equity' => $equity,
                'buy_hold_equity' => $bhEquity,
                'drawdown_pct' => $dd,
            ];

            // Step D: Evaluate bar i close signal strictly for next bar (i + 1)
            if ($i < $n - 1) {
                $sig = $signals[$i] ?? null;
                if ($position === null && $sig === 'BUY') {
                    $pendingOrder = ['type' => 'BUY', 'signal_date' => $date, 'reason' => 'strategy_entry'];
                } elseif ($position !== null && $sig === 'SELL') {
                    $pendingOrder = ['type' => 'SELL', 'signal_date' => $date, 'reason' => 'strategy_exit'];
                }
            }
        }

        $evalCount = count($equityCurve);
        $finalEquity = $equityCurve[$evalCount - 1]['equity'];
        $finalBhEquity = $equityCurve[$evalCount - 1]['buy_hold_equity'];
        $startDate = $equityCurve[0]['date'];
        $endDate = $equityCurve[$evalCount - 1]['date'];
        $calendarDays = (int) CarbonImmutable::parse($startDate, 'UTC')->diffInDays(CarbonImmutable::parse($endDate, 'UTC'));

        $totalReturnPct = Dec::pctChange($finalEquity, $initialCapital, 8) ?? '0.00000000';
        $buyHoldReturnPct = Dec::pctChange($finalBhEquity, $initialCapital, 8) ?? '0.00000000';
        $excessReturnPct = Dec::sub($totalReturnPct, $buyHoldReturnPct, 8);
        $netProfit = Dec::sub($finalEquity, $initialCapital, 4);

        $cagrPct = null;
        if ($calendarDays >= 30 && Dec::cmp($finalEquity, '0') > 0 && Dec::cmp($initialCapital, '0') > 0) {
            $ratio = (float) $finalEquity / (float) $initialCapital;
            $cagrFloat = pow($ratio, 365.25 / $calendarDays) - 1.0;
            $cagrPct = Dec::round(number_format($cagrFloat, 10, '.', ''), 8);
        }

        $riskMetrics = $this->computeRiskRatios($equityCurve, $currency, $cagrPct, $maxDrawdown);

        // Trade stats
        $winCount = 0;
        $lossCount = 0;
        $grossProfitWins = '0.0000';
        $grossLossAbs = '0.0000';
        $sumWinPct = '0.00000000';
        $sumLossPct = '0.00000000';
        $sumHoldingDays = 0;

        foreach ($trades as $t) {
            $sumHoldingDays += $t['holding_days'];
            if (Dec::cmp($t['net_pnl'], '0') > 0) {
                $winCount++;
                $grossProfitWins = Dec::add($grossProfitWins, $t['net_pnl'], 4);
                $sumWinPct = Dec::add($sumWinPct, $t['return_pct'], 8);
            } else {
                $lossCount++;
                $grossLossAbs = Dec::add($grossLossAbs, Dec::mul($t['net_pnl'], '-1', 4), 4);
                $sumLossPct = Dec::add($sumLossPct, $t['return_pct'], 8);
            }
        }

        $closedCount = count($trades);
        $winRatePct = $closedCount > 0 ? Dec::div((string) $winCount, (string) $closedCount, 8) : null;
        $profitFactor = Dec::cmp($grossLossAbs, '0') > 0
            ? Dec::div($grossProfitWins, $grossLossAbs, 4)
            : ($winCount > 0 ? '99.9999' : null);
        $avgWinPct = $winCount > 0 ? Dec::div($sumWinPct, (string) $winCount, 8) : null;
        $avgLossPct = $lossCount > 0 ? Dec::div($sumLossPct, (string) $lossCount, 8) : null;
        $avgHoldingDays = $closedCount > 0 ? round($sumHoldingDays / $closedCount, 1) : null;
        $exposurePct = $evalCount > 0 ? Dec::div((string) $daysInMarket, (string) $evalCount, 8) : '0.00000000';

        // 70% / 30% Walk-Forward split transparency
        $splitIdx = max(1, (int) floor($evalCount * 0.7));
        $inSampleEq = $equityCurve[$splitIdx - 1]['equity'];
        $inSampleRetPct = Dec::pctChange($inSampleEq, $initialCapital, 8);
        $outOfSampleRetPct = ($splitIdx < $evalCount && Dec::cmp($inSampleEq, '0') > 0)
            ? Dec::pctChange($finalEquity, $inSampleEq, 8)
            : null;

        $openPositionSummary = null;
        if ($position !== null) {
            $lastClose = (string) $bars[$n - 1]['close'];
            $curVal = Dec::mul((string) $position['shares'], $lastClose, 4);
            $costBasis = Dec::add($position['entry_gross'], $position['entry_fee'], 4);
            $unrealizedPnl = Dec::sub($curVal, $costBasis, 4);
            $unrealizedPct = Dec::pctChange($curVal, $costBasis, 8);
            $openPositionSummary = [
                'signal_date' => $position['signal_date'],
                'entry_date' => $position['entry_date'],
                'shares' => $position['shares'],
                'entry_price' => $position['entry_price'],
                'mark_price' => $lastClose,
                'market_value' => $curVal,
                'unrealized_pnl' => $unrealizedPnl,
                'unrealized_pct' => $unrealizedPct,
                'stop_price' => $position['stop_price'],
                'tp_price' => $position['tp_price'],
            ];
        }

        $normalizedParams = array_merge($params, [
            'initial_capital' => $initialCapital,
            'allocation_pct' => $allocationPct,
            'buy_fee_pct' => $buyFeePct,
            'sell_fee_pct' => $sellFeePct,
            'slippage_pct' => $slippagePct,
            'stop_loss_pct' => $stopLossPct,
            'take_profit_pct' => $takeProfitPct,
        ]);

        return [
            'instrument' => [
                'id' => $instrument->id,
                'exchange_code' => $exchangeCode,
                'symbol' => $instrument->symbol,
                'name' => $instrument->name,
                'currency' => $currency,
                'lot_size' => $lotSize,
            ],
            'strategy' => $strategy,
            'range' => $range,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'trading_days' => $evalCount,
            'calendar_days' => $calendarDays,
            'is_demo' => $isDemo,
            'parameters' => $normalizedParams,
            'safeguards' => [
                'execution_rule' => 'next_bar_open',
                'sl_tp_tie_break' => 'stop_loss_first',
                'lot_rounding' => 'floor_whole_lots',
                'tick_rounding' => 'exchange_tick_table',
            ],
            'summary' => [
                'initial_capital' => $initialCapital,
                'final_equity' => $finalEquity,
                'net_profit' => $netProfit,
                'total_return_pct' => $totalReturnPct,
                'cagr_pct' => $cagrPct,
                'buy_hold_final_equity' => $finalBhEquity,
                'buy_hold_return_pct' => $buyHoldReturnPct,
                'excess_return_pct' => $excessReturnPct,
                'max_drawdown_pct' => $maxDrawdown,
                'annualized_volatility_pct' => $riskMetrics['volatility_pct'],
                'sharpe_ratio' => $riskMetrics['sharpe_ratio'],
                'sortino_ratio' => $riskMetrics['sortino_ratio'],
                'calmar_ratio' => $riskMetrics['calmar_ratio'],
                'closed_trades_count' => $closedCount,
                'win_count' => $winCount,
                'loss_count' => $lossCount,
                'win_rate_pct' => $winRatePct,
                'profit_factor' => $profitFactor,
                'avg_win_pct' => $avgWinPct,
                'avg_loss_pct' => $avgLossPct,
                'avg_holding_days' => $avgHoldingDays,
                'exposure_time_pct' => $exposurePct,
                'total_fees_paid' => $totalFeesPaid,
                'in_sample_return_pct' => $inSampleRetPct,
                'out_of_sample_return_pct' => $outOfSampleRetPct,
                'in_sample_split_date' => $equityCurve[$splitIdx - 1]['date'],
            ],
            'open_position' => $openPositionSummary,
            'trades' => $trades,
            'equity_curve' => $equityCurve,
        ];
    }

    /**
     * Formula-injection-safe UTF-8 BOM CSV export of executed trades and backtest summary.
     *
     * @param  array<string, mixed>  $result
     */
    public function exportBacktestCsv(array $result): string
    {
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, "\xEF\xBB\xBF");

        $s = $result['summary'] ?? [];
        $meta = [
            ['Dataset', 'NADI Strategy Lab Backtest'],
            ['Exchange', (string) ($result['instrument']['exchange_code'] ?? '')],
            ['Symbol', (string) ($result['instrument']['symbol'] ?? '')],
            ['Strategy', (string) ($result['strategy'] ?? '')],
            ['Range', (string) ($result['range'] ?? '')],
            ['Window', ($result['start_date'] ?? '').' .. '.($result['end_date'] ?? '')],
            ['Currency', (string) ($result['instrument']['currency'] ?? 'IDR')],
            ['ExecutionRule', 'Next-Bar Open (t+1)'],
            ['InitialCapital', (string) ($s['initial_capital'] ?? '0')],
            ['FinalEquity', (string) ($s['final_equity'] ?? '0')],
            ['TotalReturnPct', (string) ($s['total_return_pct'] ?? '0')],
            ['BuyAndHoldReturnPct', (string) ($s['buy_hold_return_pct'] ?? '0')],
            ['MaxDrawdownPct', (string) ($s['max_drawdown_pct'] ?? '0')],
            ['ContainsDemoData', ($result['is_demo'] ?? false) ? 'YES' : 'NO'],
        ];

        foreach ($meta as $m) {
            fputcsv($handle, array_map([CsvSanitizer::class, 'escapeCell'], $m));
        }

        fputcsv($handle, []);
        fputcsv($handle, array_map([CsvSanitizer::class, 'escapeCell'], [
            'TradeNo', 'SignalDate', 'EntryDate', 'ExitDate', 'HoldingDays',
            'Shares', 'EntryPrice', 'ExitPrice', 'GrossPnl', 'FeesPaid', 'NetPnl', 'ReturnPct', 'ExitReason',
        ]));

        foreach ($result['trades'] ?? [] as $t) {
            fputcsv($handle, array_map([CsvSanitizer::class, 'escapeCell'], [
                (string) ($t['trade_no'] ?? ''),
                (string) ($t['signal_date'] ?? ''),
                (string) ($t['entry_date'] ?? ''),
                (string) ($t['exit_date'] ?? ''),
                (string) ($t['holding_days'] ?? 0),
                (string) ($t['shares'] ?? 0),
                (string) ($t['entry_price'] ?? '0'),
                (string) ($t['exit_price'] ?? '0'),
                (string) ($t['gross_pnl'] ?? '0'),
                (string) ($t['total_fees'] ?? '0'),
                (string) ($t['net_pnl'] ?? '0'),
                (string) ($t['return_pct'] ?? '0'),
                (string) ($t['exit_reason'] ?? ''),
            ]));
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function normalizeStrategyParameters(string $strategy, array $options): array
    {
        return match ($strategy) {
            'sma_crossover' => (function () use ($options): array {
                $fast = max(5, min(100, (int) ($options['fast_period'] ?? 20)));
                $slow = max($fast + 5, min(200, (int) ($options['slow_period'] ?? 50)));

                return ['fast_period' => $fast, 'slow_period' => $slow];
            })(),
            'rsi_mean_reversion' => (function () use ($options): array {
                $period = max(5, min(30, (int) ($options['rsi_period'] ?? 14)));
                $oversold = max(15, min(45, (int) ($options['oversold'] ?? 30)));
                $overbought = max(55, min(85, (int) ($options['overbought'] ?? 70)));

                return ['rsi_period' => $period, 'oversold' => $oversold, 'overbought' => $overbought];
            })(),
            'macd_momentum' => (function () use ($options): array {
                $fast = max(5, min(30, (int) ($options['fast_ema'] ?? 12)));
                $slow = max($fast + 5, min(60, (int) ($options['slow_ema'] ?? 26)));
                $sig = max(3, min(20, (int) ($options['signal_ema'] ?? 9)));

                return ['fast_ema' => $fast, 'slow_ema' => $slow, 'signal_ema' => $sig];
            })(),
            'donchian_breakout' => [
                'entry_lookback' => max(10, min(100, (int) ($options['entry_lookback'] ?? 20))),
                'exit_lookback' => max(5, min(50, (int) ($options['exit_lookback'] ?? 10))),
            ],
        };
    }

    /**
     * @param  list<array{date: string, open: string|float, high: string|float, low: string|float, close: string|float}>  $bars
     * @param  array<string, mixed>  $params
     * @return list<'BUY'|'SELL'|null>
     */
    private function computeSignals(array $bars, string $strategy, array $params): array
    {
        $n = count($bars);
        $closes = array_map(fn ($b) => (float) $b['close'], $bars);
        $highs = array_map(fn ($b) => (float) $b['high'], $bars);
        $lows = array_map(fn ($b) => (float) $b['low'], $bars);
        $signals = array_fill(0, $n, null);

        if ($strategy === 'sma_crossover') {
            $fast = $this->indicators->sma($closes, (int) $params['fast_period']);
            $slow = $this->indicators->sma($closes, (int) $params['slow_period']);
            for ($i = 1; $i < $n; $i++) {
                if ($fast[$i - 1] !== null && $slow[$i - 1] !== null && $fast[$i] !== null && $slow[$i] !== null) {
                    if ($fast[$i - 1] <= $slow[$i - 1] && $fast[$i] > $slow[$i]) {
                        $signals[$i] = 'BUY';
                    } elseif ($fast[$i - 1] >= $slow[$i - 1] && $fast[$i] < $slow[$i]) {
                        $signals[$i] = 'SELL';
                    }
                }
            }
        } elseif ($strategy === 'rsi_mean_reversion') {
            $rsi = $this->indicators->rsi($closes, (int) $params['rsi_period']);
            $os = (float) $params['oversold'];
            $ob = (float) $params['overbought'];
            for ($i = 0; $i < $n; $i++) {
                if ($rsi[$i] !== null) {
                    if ($rsi[$i] <= $os) {
                        $signals[$i] = 'BUY';
                    } elseif ($rsi[$i] >= $ob) {
                        $signals[$i] = 'SELL';
                    }
                }
            }
        } elseif ($strategy === 'macd_momentum') {
            $macd = $this->indicators->macd(
                $closes,
                (int) $params['fast_ema'],
                (int) $params['slow_ema'],
                (int) $params['signal_ema'],
            );
            $hist = $macd['hist'];
            for ($i = 1; $i < $n; $i++) {
                if ($hist[$i - 1] !== null && $hist[$i] !== null) {
                    if ($hist[$i - 1] <= 0.0 && $hist[$i] > 0.0) {
                        $signals[$i] = 'BUY';
                    } elseif ($hist[$i - 1] >= 0.0 && $hist[$i] < 0.0) {
                        $signals[$i] = 'SELL';
                    }
                }
            }
        } elseif ($strategy === 'donchian_breakout') {
            $entryLb = (int) $params['entry_lookback'];
            $exitLb = (int) $params['exit_lookback'];
            for ($i = max($entryLb, $exitLb); $i < $n; $i++) {
                // Strictly prior channel highs/lows [i - lookback .. i - 1], never including bar i
                $priorHigh = max(array_slice($highs, $i - $entryLb, $entryLb));
                $priorLow = min(array_slice($lows, $i - $exitLb, $exitLb));
                if ($closes[$i] > $priorHigh) {
                    $signals[$i] = 'BUY';
                } elseif ($closes[$i] < $priorLow) {
                    $signals[$i] = 'SELL';
                }
            }
        }

        return $signals;
    }

    /**
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>
     */
    private function closePosition(
        array $position,
        string $exitDate,
        string $execPx,
        string $sellFeePct,
        string $reason,
        int $tradeNo,
    ): array {
        $shares = (int) $position['shares'];
        $exitGross = Dec::mul($execPx, (string) $shares, 4);
        $exitFee = Dec::mul($exitGross, $sellFeePct, 4);
        $netProceeds = Dec::sub($exitGross, $exitFee, 4);

        $entryTotalCost = Dec::add($position['entry_gross'], $position['entry_fee'], 4);
        $grossPnl = Dec::sub($exitGross, $position['entry_gross'], 4);
        $totalFees = Dec::add($position['entry_fee'], $exitFee, 4);
        $netPnl = Dec::sub($netProceeds, $entryTotalCost, 4);
        $returnPct = Dec::pctChange($netProceeds, $entryTotalCost, 8) ?? '0.00000000';

        $holdingDays = (int) CarbonImmutable::parse($position['entry_date'], 'UTC')
            ->diffInDays(CarbonImmutable::parse($exitDate, 'UTC'));

        return [
            'trade_no' => $tradeNo,
            'signal_date' => $position['signal_date'],
            'entry_date' => $position['entry_date'],
            'exit_date' => $exitDate,
            'holding_days' => max(1, $holdingDays),
            'shares' => $shares,
            'entry_price' => $position['entry_price'],
            'exit_price' => $execPx,
            'gross_pnl' => $grossPnl,
            'exit_fee' => $exitFee,
            'total_fees' => $totalFees,
            'net_proceeds' => $netProceeds,
            'net_pnl' => $netPnl,
            'return_pct' => $returnPct,
            'exit_reason' => $reason,
        ];
    }

    /**
     * @param  list<array{date: string, equity: string}>  $equityCurve
     * @return array{volatility_pct: ?string, sharpe_ratio: ?string, sortino_ratio: ?string, calmar_ratio: ?string}
     */
    private function computeRiskRatios(array $equityCurve, string $currency, ?string $cagrPct, string $maxDrawdown): array
    {
        $n = count($equityCurve);
        if ($n < 21) {
            return [
                'volatility_pct' => null,
                'sharpe_ratio' => null,
                'sortino_ratio' => null,
                'calmar_ratio' => null,
            ];
        }

        $dailyReturns = [];
        for ($i = 1; $i < $n; $i++) {
            $prev = (float) $equityCurve[$i - 1]['equity'];
            $cur = (float) $equityCurve[$i]['equity'];
            if ($prev <= 0.0) {
                continue;
            }
            $dailyReturns[] = ($cur - $prev) / $prev;
        }

        $obs = count($dailyReturns);
        if ($obs < 20) {
            return [
                'volatility_pct' => null,
                'sharpe_ratio' => null,
                'sortino_ratio' => null,
                'calmar_ratio' => null,
            ];
        }

        $rfAnnual = (float) config("nadi.risk_free_rates.{$currency}", config('nadi.risk_free_rates.DEFAULT', '0.0450'));
        $rfDaily = pow(1.0 + $rfAnnual, 1.0 / 252.0) - 1.0;

        $mean = array_sum($dailyReturns) / $obs;
        $sqSum = 0.0;
        $downsideSqSum = 0.0;
        foreach ($dailyReturns as $r) {
            $sqSum += ($r - $mean) ** 2;
            $diffRf = $r - $rfDaily;
            if ($diffRf < 0.0) {
                $downsideSqSum += $diffRf ** 2;
            }
        }

        $dailyVol = sqrt($sqSum / ($obs - 1));
        $annVol = $dailyVol * sqrt(252.0);
        $annExcess = ($mean - $rfDaily) * 252.0;

        $sharpe = $annVol > 1e-12 ? $annExcess / $annVol : null;
        $downDev = sqrt($downsideSqSum / $obs) * sqrt(252.0);
        $sortino = $downDev > 1e-12 ? $annExcess / $downDev : null;

        $calmar = null;
        if ($cagrPct !== null && Dec::cmp($maxDrawdown, '0') < 0) {
            $mddAbs = abs((float) $maxDrawdown);
            if ($mddAbs > 1e-9) {
                $calmar = (float) $cagrPct / $mddAbs;
            }
        }

        return [
            'volatility_pct' => Dec::round(number_format($annVol, 10, '.', ''), 8),
            'sharpe_ratio' => $sharpe !== null ? Dec::round(number_format($sharpe, 10, '.', ''), 6) : null,
            'sortino_ratio' => $sortino !== null ? Dec::round(number_format($sortino, 10, '.', ''), 6) : null,
            'calmar_ratio' => $calmar !== null ? Dec::round(number_format($calmar, 10, '.', ''), 6) : null,
        ];
    }

    private function clampFraction(mixed $raw, string $min, string $max, string $fallback, int $scale): string
    {
        if ($raw === null || $raw === '' || ! is_numeric((string) $raw)) {
            return Dec::round($fallback, $scale);
        }
        $val = (string) $raw;
        if (Dec::cmp($val, $min) < 0) {
            return Dec::round($min, $scale);
        }
        if (Dec::cmp($val, $max) > 0) {
            return Dec::round($max, $scale);
        }

        return Dec::round($val, $scale);
    }

    private function parseOptionalFraction(mixed $raw, string $min, string $max, int $scale): ?string
    {
        if ($raw === null || trim((string) $raw) === '' || ! is_numeric((string) $raw)) {
            return null;
        }
        $val = (string) $raw;
        if (Dec::cmp($val, $min) < 0) {
            return Dec::round($min, $scale);
        }
        if (Dec::cmp($val, $max) > 0) {
            return Dec::round($max, $scale);
        }

        return Dec::round($val, $scale);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function emptyResult(
        Instrument $instrument,
        string $strategy,
        string $range,
        string $currency,
        int $lotSize,
        string $initialCapital,
        array $params,
        bool $isDemo,
    ): array {
        return [
            'instrument' => [
                'id' => $instrument->id,
                'exchange_code' => strtoupper($instrument->exchange?->code ?? 'IDX'),
                'symbol' => $instrument->symbol,
                'name' => $instrument->name,
                'currency' => $currency,
                'lot_size' => $lotSize,
            ],
            'strategy' => $strategy,
            'range' => $range,
            'start_date' => null,
            'end_date' => null,
            'trading_days' => 0,
            'calendar_days' => 0,
            'is_demo' => $isDemo,
            'parameters' => array_merge($params, ['initial_capital' => $initialCapital]),
            'safeguards' => [
                'execution_rule' => 'next_bar_open',
                'sl_tp_tie_break' => 'stop_loss_first',
                'lot_rounding' => 'floor_whole_lots',
                'tick_rounding' => 'exchange_tick_table',
            ],
            'summary' => [
                'initial_capital' => $initialCapital,
                'final_equity' => $initialCapital,
                'net_profit' => '0.0000',
                'total_return_pct' => '0.00000000',
                'cagr_pct' => null,
                'buy_hold_final_equity' => $initialCapital,
                'buy_hold_return_pct' => '0.00000000',
                'excess_return_pct' => '0.00000000',
                'max_drawdown_pct' => '0.00000000',
                'annualized_volatility_pct' => null,
                'sharpe_ratio' => null,
                'sortino_ratio' => null,
                'calmar_ratio' => null,
                'closed_trades_count' => 0,
                'win_count' => 0,
                'loss_count' => 0,
                'win_rate_pct' => null,
                'profit_factor' => null,
                'avg_win_pct' => null,
                'avg_loss_pct' => null,
                'avg_holding_days' => null,
                'exposure_time_pct' => '0.00000000',
                'total_fees_paid' => '0.0000',
                'in_sample_return_pct' => null,
                'out_of_sample_return_pct' => null,
                'in_sample_split_date' => null,
            ],
            'open_position' => null,
            'trades' => [],
            'equity_curve' => [],
        ];
    }
}
