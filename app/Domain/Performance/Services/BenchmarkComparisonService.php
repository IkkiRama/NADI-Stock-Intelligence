<?php

namespace App\Domain\Performance\Services;

use App\Domain\MarketData\Services\BatchFxLookup;
use App\Models\IndexValueDaily;
use App\Models\StockIndex;
use App\Support\Math\Dec;
use Carbon\CarbonImmutable;

/**
 * Portfolio vs Benchmark Comparison Service.
 *
 * Aligns portfolio TWR curves against global equity indices (IHSG, LQ45, SPX,
 * NDX, DJI, STI, CSI300, HSI) with optional reporting-currency FX translation,
 * active return/excess return, rolling 20D alpha, tracking error, and
 * information ratio. Never compares mismatched currencies without explicit notice.
 */
final class BenchmarkComparisonService
{
    public const MIN_OBSERVATIONS_FOR_TE = 20;

    /**
     * @return list<array<string, mixed>>
     */
    public function listBenchmarks(): array
    {
        return StockIndex::query()
            ->where('is_active', true)
            ->where('is_benchmark', true)
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'country_code', 'currency', 'index_type'])
            ->map(fn (StockIndex $idx): array => [
                'id' => $idx->id,
                'code' => $idx->code,
                'name' => $idx->name,
                'country_code' => $idx->country_code,
                'currency' => $idx->currency,
                'index_type' => $idx->index_type ?: 'price',
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $windowSeries
     * @param  array<string, mixed>  $twrResult
     * @return array<string, mixed>
     */
    public function compare(
        array $windowSeries,
        array $twrResult,
        string $benchmarkCode = 'IHSG',
        string $currencyMode = 'reporting_aligned',
    ): array {
        $requestedCode = strtoupper(trim($benchmarkCode ?: config('nadi.defaults.benchmark', 'IHSG')));
        $mode = in_array($currencyMode, ['reporting_aligned', 'native_index'], true)
            ? $currencyMode
            : 'reporting_aligned';

        $index = StockIndex::query()
            ->where('is_active', true)
            ->where('code', $requestedCode)
            ->first()
            ?? StockIndex::query()->where('is_active', true)->where('code', 'IHSG')->first()
            ?? StockIndex::query()->where('is_active', true)->where('is_benchmark', true)->first();

        $reportingCcy = strtoupper((string) ($windowSeries['reporting_currency'] ?? 'IDR'));

        if (! $index) {
            return $this->emptyComparison($requestedCode, $reportingCcy, $mode);
        }

        $dailyTwr = $twrResult['daily_series'] ?? [];
        $indexCcy = strtoupper($index->currency);
        $differsFromReporting = $indexCcy !== $reportingCcy;

        if (empty($dailyTwr)) {
            return $this->emptyComparison(
                $index->code,
                $reportingCcy,
                $mode,
                $index->name,
                $index->country_code,
                $indexCcy,
                $index->index_type ?: 'price',
                $differsFromReporting,
            );
        }

        $startDate = (string) $dailyTwr[0]['date'];
        $endDate = (string) end($dailyTwr)['date'];
        $lookback = CarbonImmutable::parse($startDate, 'UTC')->subDays(14)->toDateString();

        $bars = IndexValueDaily::query()
            ->where('stock_index_id', $index->id)
            ->whereDate('trade_date', '>=', $lookback)
            ->whereDate('trade_date', '<=', $endDate)
            ->orderBy('trade_date')
            ->get(['trade_date', 'close', 'is_demo']);

        if ($bars->isEmpty()) {
            return $this->emptyComparison(
                $index->code,
                $reportingCcy,
                $mode,
                $index->name,
                $index->country_code,
                $indexCcy,
                $index->index_type ?: 'price',
                $differsFromReporting,
            );
        }

        $fxLookup = ($mode === 'reporting_aligned' && $differsFromReporting) ? BatchFxLookup::load() : null;

        $missingFx = false;
        $benchByDate = [];
        $benchIsDemo = false;
        foreach ($bars as $bar) {
            $d = $bar->trade_date->toDateString();
            $benchIsDemo = $benchIsDemo || (bool) $bar->is_demo;
            $level = (string) $bar->close;

            if ($fxLookup !== null) {
                $fx = $fxLookup->resolve($indexCcy, $reportingCcy, $d);
                if ($fx === null) {
                    $missingFx = true;
                    continue;
                }
                $level = Dec::mul($level, $fx, 6);
            }

            $benchByDate[$d] = $level;
        }

        $effectiveMode = $mode;
        if ($mode === 'reporting_aligned' && $differsFromReporting && empty($benchByDate) && $missingFx) {
            // Cannot convert without FX rate — safely disclose FX gap rather than faking alignment
            return $this->emptyComparison(
                $index->code,
                $reportingCcy,
                $mode,
                $index->name,
                $index->country_code,
                $indexCcy,
                $index->index_type ?: 'price',
                true,
                true,
            );
        }

        // Track forward-filled benchmark close up to each portfolio session date
        $benchDates = array_keys($benchByDate);
        sort($benchDates);

        $baseBench = null;
        $basePortFactor = null;
        $prevBenchLevel = null;
        $prevBenchDateUsed = null;
        $pendingPortfolioFactor = 1.0;
        $peakRelative = 1.0;

        $series = [];
        $activeDailyReturns = [];
        $sessionPairReturns = [];
        $rollingExcess = [];

        foreach ($dailyTwr as $row) {
            $date = (string) $row['date'];
            $benchLevel = $this->levelOnOrBefore($benchByDate, $date);
            $benchHasBarOnDate = isset($benchByDate[$date]);

            if ($benchLevel === null || Dec::cmp($benchLevel, '0') <= 0) {
                continue;
            }

            $portFactor = Dec::add('1', (string) $row['cumulative_twr'], 10);
            if ($baseBench === null) {
                $baseBench = $benchLevel;
                $basePortFactor = Dec::cmp($portFactor, '0') > 0 ? $portFactor : '1.0000000000';
                $prevBenchLevel = $benchLevel;
                $prevBenchDateUsed = $date;
                $pendingPortfolioFactor = 1.0;
            } else {
                $pendingPortfolioFactor *= (1.0 + (float) $row['daily_return']);
            }

            $benchCumFactor = Dec::div($benchLevel, $baseBench, 10) ?? '1.0000000000';
            $portCumFactor = Dec::div($portFactor, $basePortFactor, 10) ?? '1.0000000000';

            $portIndex = Dec::mul($portCumFactor, '100', 4);
            $benchIndex = Dec::mul($benchCumFactor, '100', 4);
            $portReturn = Dec::sub($portCumFactor, '1', 8);
            $benchReturn = Dec::sub($benchCumFactor, '1', 8);
            $excess = Dec::sub($portReturn, $benchReturn, 8);

            $relWealth = (float) $benchCumFactor > 0 ? ((float) $portCumFactor / (float) $benchCumFactor) : 1.0;
            if ($relWealth > $peakRelative) {
                $peakRelative = $relWealth;
            }
            $relDrawdown = $peakRelative > 0 ? ($relWealth / $peakRelative) - 1.0 : 0.0;

            $series[] = [
                'date' => $date,
                'portfolio_index' => $portIndex,
                'portfolio_twr' => $portReturn,
                'benchmark_index' => $benchIndex,
                'benchmark_return' => $benchReturn,
                'excess_return' => $excess,
                'relative_drawdown' => Dec::round(number_format($relDrawdown, 10, '.', ''), 8),
            ];

            if ($date !== $prevBenchDateUsed && $benchHasBarOnDate && $prevBenchLevel !== null && Dec::cmp($prevBenchLevel, '0') > 0) {
                $rb = (float) Dec::sub(Dec::div($benchLevel, $prevBenchLevel, 10) ?? '1', '1', 10);
                $rp = $pendingPortfolioFactor - 1.0;
                $activeDailyReturns[] = $rp - $rb;
                $sessionPairReturns[] = ['date' => $date, 'rp' => $rp, 'rb' => $rb];
                $prevBenchLevel = $benchLevel;
                $prevBenchDateUsed = $date;
                $pendingPortfolioFactor = 1.0;

                $nPairs = count($sessionPairReturns);
                if ($nPairs >= self::MIN_OBSERVATIONS_FOR_TE) {
                    $slice = array_slice($sessionPairReturns, -self::MIN_OBSERVATIONS_FOR_TE);
                    $gP = 1.0;
                    $gB = 1.0;
                    foreach ($slice as $pair) {
                        $gP *= (1.0 + $pair['rp']);
                        $gB *= (1.0 + $pair['rb']);
                    }
                    $rollingExcess[] = [
                        'date' => $date,
                        'excess_20d' => Dec::round(number_format(($gP - 1.0) - ($gB - 1.0), 10, '.', ''), 8),
                    ];
                }
            }
        }

        $obsCount = count($activeDailyReturns);
        $trackingError = null;
        $informationRatio = null;

        if ($obsCount >= self::MIN_OBSERVATIONS_FOR_TE) {
            $mean = array_sum($activeDailyReturns) / $obsCount;
            $sumSq = 0.0;
            foreach ($activeDailyReturns as $a) {
                $sumSq += ($a - $mean) ** 2;
            }
            $sampleStd = sqrt($sumSq / ($obsCount - 1));
            $annTe = $sampleStd * sqrt(252.0);
            if ($annTe > 0.0 && is_finite($annTe)) {
                $trackingError = Dec::round(number_format($annTe, 10, '.', ''), 8);
                $ir = ($mean / $sampleStd) * sqrt(252.0);
                if (is_finite($ir)) {
                    $informationRatio = Dec::round(number_format($ir, 8, '.', ''), 4);
                }
            } else {
                $trackingError = '0.00000000';
            }
        }

        $lastPoint = ! empty($series) ? end($series) : null;
        $portfolioTwrAligned = $lastPoint ? $lastPoint['portfolio_twr'] : ($twrResult['twr_total'] ?? null);
        $benchmarkReturn = $lastPoint ? $lastPoint['benchmark_return'] : null;
        $excessReturn = $lastPoint ? $lastPoint['excess_return'] : null;

        return [
            'has_data' => count($series) >= 2,
            'code' => $index->code,
            'name' => $index->name,
            'country_code' => $index->country_code,
            'index_currency' => $indexCcy,
            'reporting_currency' => $reportingCcy,
            'index_type' => $index->index_type ?: 'price',
            'currency_mode' => $effectiveMode,
            'currency_aligned' => $effectiveMode === 'reporting_aligned' || ! $differsFromReporting,
            'currency_converted' => $effectiveMode === 'reporting_aligned' && $differsFromReporting,
            'currency_mismatch_warning' => $effectiveMode === 'native_index' && $differsFromReporting,
            'missing_fx' => $missingFx,
            'is_demo' => $benchIsDemo,
            'observations_count' => $obsCount,
            'min_observations_required' => self::MIN_OBSERVATIONS_FOR_TE,
            'portfolio_twr' => $portfolioTwrAligned,
            'benchmark_return' => $benchmarkReturn,
            'excess_return' => $excessReturn,
            'tracking_error' => $trackingError,
            'information_ratio' => $informationRatio,
            'series' => $series,
            'rolling_excess_20d' => $rollingExcess,
        ];
    }

    /**
     * @param  array<string, string>  $benchByDate
     */
    private function levelOnOrBefore(array $benchByDate, string $date): ?string
    {
        $chosen = null;
        foreach ($benchByDate as $d => $val) {
            if ($d <= $date) {
                $chosen = $val;
            } else {
                break;
            }
        }

        return $chosen;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyComparison(
        string $code,
        string $reportingCcy,
        string $mode,
        string $name = '',
        string $countryCode = '',
        string $indexCcy = '',
        string $indexType = 'price',
        bool $differs = false,
        bool $missingFx = false,
    ): array {
        return [
            'has_data' => false,
            'code' => $code,
            'name' => $name ?: $code,
            'country_code' => $countryCode,
            'index_currency' => $indexCcy ?: $reportingCcy,
            'reporting_currency' => $reportingCcy,
            'index_type' => $indexType,
            'currency_mode' => $mode,
            'currency_aligned' => $mode === 'reporting_aligned' || ! $differs,
            'currency_converted' => $mode === 'reporting_aligned' && $differs,
            'currency_mismatch_warning' => $mode === 'native_index' && $differs,
            'missing_fx' => $missingFx,
            'is_demo' => false,
            'observations_count' => 0,
            'min_observations_required' => self::MIN_OBSERVATIONS_FOR_TE,
            'portfolio_twr' => null,
            'benchmark_return' => null,
            'excess_return' => null,
            'tracking_error' => null,
            'information_ratio' => null,
            'series' => [],
            'rolling_excess_20d' => [],
        ];
    }
}
