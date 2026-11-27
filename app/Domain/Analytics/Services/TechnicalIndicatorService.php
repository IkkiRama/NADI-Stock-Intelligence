<?php

namespace App\Domain\Analytics\Services;

use App\Support\Math\Dec;

final class TechnicalIndicatorService
{
    /**
     * Compute aligned indicator series for ascending OHLCV bars.
     *
     * @param  list<array{date: string, open: string|float, high: string|float, low: string|float, close: string|float, volume: int}>  $bars
     * @return array<string, list<array{date: string, value: float}|array{date: string, macd: float|null, signal: float|null, hist: float|null}>>
     */
    public function computeSeries(array $bars): array
    {
        $n = count($bars);
        $dates = array_column($bars, 'date');
        $closes = array_map(fn ($b) => (float) $b['close'], $bars);
        $highs = array_map(fn ($b) => (float) $b['high'], $bars);
        $lows = array_map(fn ($b) => (float) $b['low'], $bars);
        $volumes = array_map(fn ($b) => (int) ($b['volume'] ?? 0), $bars);

        $sma20 = $this->sma($closes, 20);
        $sma50 = $this->sma($closes, 50);
        $sma200 = $this->sma($closes, 200);
        $ema20 = $this->ema($closes, 20);
        $ema50 = $this->ema($closes, 50);
        $rsi14 = $this->rsi($closes, 14);
        $macd = $this->macd($closes, 12, 26, 9);
        $bb = $this->bollinger($closes, 20, 2.0);
        $atr14 = $this->atr($highs, $lows, $closes, 14);
        $obv = $this->obv($closes, $volumes);

        $formatLine = function (array $vals) use ($dates, $n): array {
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                if ($vals[$i] !== null) {
                    $out[] = ['date' => $dates[$i], 'value' => round((float) $vals[$i], 6)];
                }
            }

            return $out;
        };

        $macdOut = [];
        for ($i = 0; $i < $n; $i++) {
            if ($macd['line'][$i] !== null) {
                $macdOut[] = [
                    'date' => $dates[$i],
                    'macd' => round((float) $macd['line'][$i], 6),
                    'signal' => $macd['signal'][$i] !== null ? round((float) $macd['signal'][$i], 6) : null,
                    'hist' => $macd['hist'][$i] !== null ? round((float) $macd['hist'][$i], 6) : null,
                ];
            }
        }

        $obvOut = [];
        for ($i = 0; $i < $n; $i++) {
            $obvOut[] = ['date' => $dates[$i], 'value' => $obv[$i]];
        }

        return [
            'sma20' => $formatLine($sma20),
            'sma50' => $formatLine($sma50),
            'sma200' => $formatLine($sma200),
            'ema20' => $formatLine($ema20),
            'ema50' => $formatLine($ema50),
            'rsi14' => $formatLine($rsi14),
            'macd' => $macdOut,
            'bbUpper' => $formatLine($bb['upper']),
            'bbMiddle' => $formatLine($bb['middle']),
            'bbLower' => $formatLine($bb['lower']),
            'atr14' => $formatLine($atr14),
            'obv' => $obvOut,
        ];
    }

    /**
     * Compute latest technical indicator snapshot for storage.
     *
     * @param  list<array{date: string, open: string|float, high: string|float, low: string|float, close: string|float, volume: int}>  $bars
     * @return array<string, mixed>|null
     */
    public function latestSnapshot(array $bars): ?array
    {
        $n = count($bars);
        if ($n === 0) {
            return null;
        }

        $closes = array_map(fn ($b) => (float) $b['close'], $bars);
        $highs = array_map(fn ($b) => (float) $b['high'], $bars);
        $lows = array_map(fn ($b) => (float) $b['low'], $bars);
        $volumes = array_map(fn ($b) => (int) ($b['volume'] ?? 0), $bars);

        $sma20 = $this->sma($closes, 20);
        $sma50 = $this->sma($closes, 50);
        $sma200 = $this->sma($closes, 200);
        $ema20 = $this->ema($closes, 20);
        $ema50 = $this->ema($closes, 50);
        $rsi14 = $this->rsi($closes, 14);
        $macd = $this->macd($closes, 12, 26, 9);
        $bb = $this->bollinger($closes, 20, 2.0);
        $atr14 = $this->atr($highs, $lows, $closes, 14);
        $obv = $this->obv($closes, $volumes);
        $vol20d = $this->annualizedVolatility($closes, 20);

        $last = $n - 1;
        $fmt = fn (?float $v, int $scale = 6): ?string => $v === null ? null : Dec::round(number_format($v, 10, '.', ''), $scale);

        return [
            'trade_date' => $bars[$last]['date'],
            'sma_20' => $fmt($sma20[$last], 6),
            'sma_50' => $fmt($sma50[$last], 6),
            'sma_200' => $fmt($sma200[$last], 6),
            'ema_20' => $fmt($ema20[$last], 6),
            'ema_50' => $fmt($ema50[$last], 6),
            'macd_line' => $fmt($macd['line'][$last], 6),
            'macd_signal' => $fmt($macd['signal'][$last], 6),
            'macd_hist' => $fmt($macd['hist'][$last], 6),
            'bb_upper' => $fmt($bb['upper'][$last], 6),
            'bb_middle' => $fmt($bb['middle'][$last], 6),
            'bb_lower' => $fmt($bb['lower'][$last], 6),
            'atr_14' => $fmt($atr14[$last], 6),
            'rsi_14' => $fmt($rsi14[$last], 4),
            'obv' => $obv[$last],
            'volatility_20d' => $fmt($vol20d, 8),
        ];
    }

    /**
     * @param  list<float>  $values
     * @return list<float|null>
     */
    public function sma(array $values, int $period): array
    {
        $n = count($values);
        $out = array_fill(0, $n, null);
        if ($n < $period || $period <= 0) {
            return $out;
        }

        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $values[$i];
            if ($i >= $period) {
                $sum -= $values[$i - $period];
            }
            if ($i >= $period - 1) {
                $out[$i] = $sum / $period;
            }
        }

        return $out;
    }

    /**
     * @param  list<float>  $values
     * @return list<float|null>
     */
    public function ema(array $values, int $period): array
    {
        $n = count($values);
        $out = array_fill(0, $n, null);
        if ($n < $period || $period <= 0) {
            return $out;
        }

        $seed = array_sum(array_slice($values, 0, $period)) / $period;
        $out[$period - 1] = $seed;
        $k = 2.0 / ($period + 1);
        $prev = $seed;

        for ($i = $period; $i < $n; $i++) {
            $prev = ($values[$i] - $prev) * $k + $prev;
            $out[$i] = $prev;
        }

        return $out;
    }

    /**
     * Wilder-smoothed RSI.
     *
     * @param  list<float>  $closes
     * @return list<float|null>
     */
    public function rsi(array $closes, int $period = 14): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        if ($n <= $period || $period <= 0) {
            return $out;
        }

        $gainSum = 0.0;
        $lossSum = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            if ($diff >= 0) {
                $gainSum += $diff;
            } else {
                $lossSum += abs($diff);
            }
        }

        $avgGain = $gainSum / $period;
        $avgLoss = $lossSum / $period;
        $out[$period] = $avgLoss == 0.0 ? 100.0 : 100.0 - (100.0 / (1.0 + ($avgGain / $avgLoss)));

        for ($i = $period + 1; $i < $n; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            $gain = $diff > 0 ? $diff : 0.0;
            $loss = $diff < 0 ? abs($diff) : 0.0;

            $avgGain = (($avgGain * ($period - 1)) + $gain) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $loss) / $period;

            $out[$i] = $avgLoss == 0.0 ? 100.0 : 100.0 - (100.0 / (1.0 + ($avgGain / $avgLoss)));
        }

        return $out;
    }

    /**
     * @param  list<float>  $closes
     * @return array{line: list<float|null>, signal: list<float|null>, hist: list<float|null>}
     */
    public function macd(array $closes, int $fast = 12, int $slow = 26, int $signalPeriod = 9): array
    {
        $n = count($closes);
        $emaFast = $this->ema($closes, $fast);
        $emaSlow = $this->ema($closes, $slow);

        $line = array_fill(0, $n, null);
        $validMacd = [];
        $validIndices = [];

        for ($i = 0; $i < $n; $i++) {
            if ($emaFast[$i] !== null && $emaSlow[$i] !== null) {
                $val = $emaFast[$i] - $emaSlow[$i];
                $line[$i] = $val;
                $validMacd[] = $val;
                $validIndices[] = $i;
            }
        }

        $signal = array_fill(0, $n, null);
        $hist = array_fill(0, $n, null);

        $sigEma = $this->ema($validMacd, $signalPeriod);
        foreach ($validIndices as $k => $origIdx) {
            if ($sigEma[$k] !== null) {
                $signal[$origIdx] = $sigEma[$k];
                $hist[$origIdx] = $line[$origIdx] - $sigEma[$k];
            }
        }

        return ['line' => $line, 'signal' => $signal, 'hist' => $hist];
    }

    /**
     * @param  list<float>  $closes
     * @return array{upper: list<float|null>, middle: list<float|null>, lower: list<float|null>}
     */
    public function bollinger(array $closes, int $period = 20, float $mult = 2.0): array
    {
        $n = count($closes);
        $middle = $this->sma($closes, $period);
        $upper = array_fill(0, $n, null);
        $lower = array_fill(0, $n, null);

        for ($i = $period - 1; $i < $n; $i++) {
            $mean = (float) $middle[$i];
            $sq = 0.0;
            for ($j = $i - $period + 1; $j <= $i; $j++) {
                $sq += ($closes[$j] - $mean) ** 2;
            }
            $std = sqrt($sq / $period);
            $upper[$i] = $mean + $mult * $std;
            $lower[$i] = $mean - $mult * $std;
        }

        return ['upper' => $upper, 'middle' => $middle, 'lower' => $lower];
    }

    /**
     * Wilder-smoothed ATR.
     *
     * @param  list<float>  $highs
     * @param  list<float>  $lows
     * @param  list<float>  $closes
     * @return list<float|null>
     */
    public function atr(array $highs, array $lows, array $closes, int $period = 14): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        if ($n <= $period || $period <= 0) {
            return $out;
        }

        $tr = array_fill(0, $n, 0.0);
        $tr[0] = $highs[0] - $lows[0];
        for ($i = 1; $i < $n; $i++) {
            $tr[$i] = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1])
            );
        }

        $atr = array_sum(array_slice($tr, 1, $period)) / $period;
        $out[$period] = $atr;

        for ($i = $period + 1; $i < $n; $i++) {
            $atr = (($atr * ($period - 1)) + $tr[$i]) / $period;
            $out[$i] = $atr;
        }

        return $out;
    }

    /**
     * @param  list<float>  $closes
     * @param  list<int>  $volumes
     * @return list<int>
     */
    public function obv(array $closes, array $volumes): array
    {
        $n = count($closes);
        if ($n === 0) {
            return [];
        }

        $out = array_fill(0, $n, 0);
        for ($i = 1; $i < $n; $i++) {
            if ($closes[$i] > $closes[$i - 1]) {
                $out[$i] = $out[$i - 1] + $volumes[$i];
            } elseif ($closes[$i] < $closes[$i - 1]) {
                $out[$i] = $out[$i - 1] - $volumes[$i];
            } else {
                $out[$i] = $out[$i - 1];
            }
        }

        return $out;
    }

    /**
     * Annualized sample standard deviation of trailing daily simple returns.
     *
     * @param  list<float>  $closes
     */
    public function annualizedVolatility(array $closes, int $window = 20): ?float
    {
        $n = count($closes);
        if ($n < $window + 1 || $window < 2) {
            return null;
        }

        $returns = [];
        for ($i = $n - $window; $i < $n; $i++) {
            if ($closes[$i - 1] <= 0.0) {
                return null;
            }
            $returns[] = ($closes[$i] - $closes[$i - 1]) / $closes[$i - 1];
        }

        $mean = array_sum($returns) / count($returns);
        $sq = 0.0;
        foreach ($returns as $r) {
            $sq += ($r - $mean) ** 2;
        }

        $sampleStd = sqrt($sq / (count($returns) - 1));

        return $sampleStd * sqrt(252.0);
    }
}
