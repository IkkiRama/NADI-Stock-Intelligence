<?php

namespace App\Domain\Risk\Services;

use App\Support\Math\Dec;
use Carbon\CarbonImmutable;

/**
 * Portfolio Risk, Drawdown, Volatility, VaR, Risk-Adjusted Ratios, and Beta Engine.
 *
 * Uses cash-flow-neutral TWR daily wealth trajectory (`1 + cumulative_twr`) for
 * drawdown analysis and strictly enforces a >= 20-observation sample floor before
 * publishing volatility, Sharpe, Sortino, VaR, Beta, or Capture ratios.
 */
final class PortfolioRiskAnalyticsService
{
    public const MIN_OBSERVATIONS = 20;

    /**
     * @param  array<string, mixed>  $windowSeries
     * @param  array<string, mixed>  $twrResult
     * @param  array<string, mixed>  $benchmarkComparison
     * @return array<string, mixed>
     */
    public function analyze(
        array $windowSeries,
        array $twrResult,
        array $benchmarkComparison,
        string $totalEquityRep,
        string $riskFreeRateAnnual = '0.0600',
    ): array {
        $dailySeries = $twrResult['daily_series'] ?? [];
        if (empty($dailySeries)) {
            return $this->emptyResult($riskFreeRateAnnual);
        }

        // 1. Underwater Drawdown Curve & Drawdown Episodes
        $underwater = [];
        $episodes = [];
        $openEpisode = null;

        $firstW = max(0.0, 1.0 + (float) $dailySeries[0]['cumulative_twr']);
        $peakW = $firstW > 0.0 ? $firstW : 1.0;
        $peakDate = (string) $dailySeries[0]['date'];

        foreach ($dailySeries as $row) {
            $date = (string) $row['date'];
            $w = max(0.0, 1.0 + (float) $row['cumulative_twr']);

            if ($w >= $peakW) {
                if ($openEpisode !== null) {
                    $openEpisode['recovery_date'] = $date;
                    $openEpisode['days_to_recovery'] = max(
                        0,
                        (int) CarbonImmutable::parse($openEpisode['trough_date'], 'UTC')
                            ->diffInDays(CarbonImmutable::parse($date, 'UTC')),
                    );
                    $openEpisode['status'] = 'recovered';
                    $episodes[] = $openEpisode;
                    $openEpisode = null;
                }
                $peakW = $w;
                $peakDate = $date;
                $dd = 0.0;
            } else {
                $dd = $peakW > 0.0 ? ($w / $peakW) - 1.0 : 0.0;
                if ($openEpisode === null) {
                    $openEpisode = [
                        'peak_date' => $peakDate,
                        'trough_date' => $date,
                        'recovery_date' => null,
                        'depth_pct' => $dd,
                        'days_to_trough' => max(
                            0,
                            (int) CarbonImmutable::parse($peakDate, 'UTC')
                                ->diffInDays(CarbonImmutable::parse($date, 'UTC')),
                        ),
                        'days_to_recovery' => null,
                        'status' => 'underwater',
                    ];
                } elseif ($dd < $openEpisode['depth_pct']) {
                    $openEpisode['depth_pct'] = $dd;
                    $openEpisode['trough_date'] = $date;
                    $openEpisode['days_to_trough'] = max(
                        0,
                        (int) CarbonImmutable::parse($openEpisode['peak_date'], 'UTC')
                            ->diffInDays(CarbonImmutable::parse($date, 'UTC')),
                    );
                }
            }

            $underwater[] = [
                'date' => $date,
                'drawdown' => Dec::round(number_format($dd, 10, '.', ''), 8),
                'wealth_index' => Dec::round(number_format($w * 100.0, 8, '.', ''), 4),
                'peak_index' => Dec::round(number_format($peakW * 100.0, 8, '.', ''), 4),
            ];
        }

        if ($openEpisode !== null) {
            $episodes[] = $openEpisode;
        }

        usort($episodes, fn ($a, $b) => $a['depth_pct'] <=> $b['depth_pct']);

        $formattedEpisodes = [];
        foreach (array_slice($episodes, 0, 5) as $ep) {
            $formattedEpisodes[] = [
                'peak_date' => $ep['peak_date'],
                'trough_date' => $ep['trough_date'],
                'recovery_date' => $ep['recovery_date'],
                'depth_pct' => Dec::round(number_format($ep['depth_pct'], 10, '.', ''), 8),
                'days_to_trough' => $ep['days_to_trough'],
                'days_to_recovery' => $ep['days_to_recovery'],
                'status' => $ep['status'],
            ];
        }

        $maxDdRow = $formattedEpisodes[0] ?? null;
        $maxDrawdownPct = $maxDdRow ? $maxDdRow['depth_pct'] : '0.00000000';
        $currentDrawdownPct = ! empty($underwater) ? end($underwater)['drawdown'] : '0.00000000';

        // 2. Daily Returns for Volatility, Sharpe, Sortino, Historical VaR
        $dailyReturns = [];
        foreach ($dailySeries as $idx => $row) {
            $r = (float) $row['daily_return'];
            if ($idx === 0 && abs($r) < 1e-12 && abs((float) $row['cumulative_twr']) < 1e-12) {
                continue;
            }
            $dailyReturns[] = $r;
        }

        $obsCount = count($dailyReturns);
        $sufficient = $obsCount >= self::MIN_OBSERVATIONS;
        $rfAnnual = max(0.0, (float) $riskFreeRateAnnual);
        $rfDaily = ((1.0 + $rfAnnual) ** (1.0 / 252.0)) - 1.0;

        $annVol = null;
        $downsideDev = null;
        $sharpe = null;
        $sortino = null;
        $calmar = null;
        $var95Pct = null;
        $var95Rep = null;
        $cvar95Pct = null;
        $positiveDaysPct = null;

        if ($obsCount > 0) {
            $posCount = count(array_filter($dailyReturns, fn (float $r): bool => $r > 0.0));
            $positiveDaysPct = Dec::round(number_format($posCount / $obsCount, 10, '.', ''), 4);
        }

        if ($sufficient) {
            $mean = array_sum($dailyReturns) / $obsCount;
            $sumSq = 0.0;
            $sumDownSq = 0.0;
            foreach ($dailyReturns as $r) {
                $sumSq += ($r - $mean) ** 2;
                if ($r < $rfDaily) {
                    $sumDownSq += ($r - $rfDaily) ** 2;
                }
            }

            $dailyStd = sqrt($sumSq / ($obsCount - 1));
            $annVolVal = $dailyStd * sqrt(252.0);
            if (is_finite($annVolVal)) {
                $annVol = Dec::round(number_format($annVolVal, 10, '.', ''), 8);
            }

            $annDownVal = sqrt($sumDownSq / $obsCount) * sqrt(252.0);
            if (is_finite($annDownVal)) {
                $downsideDev = Dec::round(number_format($annDownVal, 10, '.', ''), 8);
            }

            if ($dailyStd > 1e-12) {
                $sharpeVal = (($mean - $rfDaily) / $dailyStd) * sqrt(252.0);
                if (is_finite($sharpeVal)) {
                    $sharpe = Dec::round(number_format($sharpeVal, 8, '.', ''), 4);
                }
            }

            if ($annDownVal > 1e-12) {
                $sortinoVal = (($mean - $rfDaily) * 252.0) / $annDownVal;
                if (is_finite($sortinoVal)) {
                    $sortino = Dec::round(number_format($sortinoVal, 8, '.', ''), 4);
                }
            }

            $twrAnn = $twrResult['twr_annualized'] ?? null;
            if ($twrAnn !== null && abs((float) $maxDrawdownPct) > 1e-6) {
                $calmarVal = (float) $twrAnn / abs((float) $maxDrawdownPct);
                if (is_finite($calmarVal)) {
                    $calmar = Dec::round(number_format($calmarVal, 8, '.', ''), 4);
                }
            }

            // Historical 95% Daily VaR & Expected Shortfall (CVaR)
            $sorted = $dailyReturns;
            sort($sorted);
            $tailIdx = max(0, min($obsCount - 1, (int) floor(0.05 * $obsCount)));
            $varVal = min(0.0, $sorted[$tailIdx]);
            $tailSlice = array_slice($sorted, 0, $tailIdx + 1);
            $cvarVal = ! empty($tailSlice) ? min(0.0, array_sum($tailSlice) / count($tailSlice)) : $varVal;

            $var95Pct = Dec::round(number_format($varVal, 10, '.', ''), 8);
            $cvar95Pct = Dec::round(number_format($cvarVal, 10, '.', ''), 8);
            if (Dec::cmp($totalEquityRep, '0') > 0) {
                $var95Rep = Dec::mul($totalEquityRep, Dec::round(number_format(abs($varVal), 10, '.', ''), 8), 4);
            }
        }

        // 3. Benchmark Relative Risk: Beta, Correlation, R-Squared & Capture Ratios
        $beta = null;
        $correlation = null;
        $rSquared = null;
        $upsideCapture = null;
        $downsideCapture = null;

        $benchSeries = $benchmarkComparison['series'] ?? [];
        $benchObs = (int) ($benchmarkComparison['observations_count'] ?? 0);
        if ($benchObs >= self::MIN_OBSERVATIONS && count($benchSeries) > self::MIN_OBSERVATIONS) {
            $pairedRp = [];
            $pairedRb = [];
            $prevP = (float) $benchSeries[0]['portfolio_index'];
            $prevB = (float) $benchSeries[0]['benchmark_index'];

            for ($i = 1; $i < count($benchSeries); $i++) {
                $curP = (float) $benchSeries[$i]['portfolio_index'];
                $curB = (float) $benchSeries[$i]['benchmark_index'];
                if ($prevP > 0.0 && $prevB > 0.0) {
                    $pairedRp[] = ($curP / $prevP) - 1.0;
                    $pairedRb[] = ($curB / $prevB) - 1.0;
                }
                $prevP = $curP;
                $prevB = $curB;
            }

            $m = count($pairedRp);
            if ($m >= self::MIN_OBSERVATIONS) {
                $meanP = array_sum($pairedRp) / $m;
                $meanB = array_sum($pairedRb) / $m;
                $cov = 0.0;
                $varP = 0.0;
                $varB = 0.0;
                $upP = [];
                $upB = [];
                $dnP = [];
                $dnB = [];

                for ($i = 0; $i < $m; $i++) {
                    $dp = $pairedRp[$i] - $meanP;
                    $db = $pairedRb[$i] - $meanB;
                    $cov += $dp * $db;
                    $varP += $dp ** 2;
                    $varB += $db ** 2;

                    if ($pairedRb[$i] > 0.0) {
                        $upP[] = $pairedRp[$i];
                        $upB[] = $pairedRb[$i];
                    } elseif ($pairedRb[$i] < 0.0) {
                        $dnP[] = $pairedRp[$i];
                        $dnB[] = $pairedRb[$i];
                    }
                }

                if ($varB > 1e-18) {
                    $betaVal = $cov / $varB;
                    if (is_finite($betaVal)) {
                        $beta = Dec::round(number_format($betaVal, 8, '.', ''), 4);
                    }
                    if ($varP > 1e-18) {
                        $corrVal = max(-1.0, min(1.0, $cov / sqrt($varP * $varB)));
                        $correlation = Dec::round(number_format($corrVal, 8, '.', ''), 4);
                        $rSquared = Dec::round(number_format($corrVal ** 2, 8, '.', ''), 4);
                    }
                }

                if (! empty($upB) && abs(array_sum($upB)) > 1e-12) {
                    $uc = (array_sum($upP) / count($upP)) / (array_sum($upB) / count($upB));
                    $upsideCapture = Dec::round(number_format($uc, 8, '.', ''), 4);
                }
                if (! empty($dnB) && abs(array_sum($dnB)) > 1e-12) {
                    $dc = (array_sum($dnP) / count($dnP)) / (array_sum($dnB) / count($dnB));
                    $downsideCapture = Dec::round(number_format($dc, 8, '.', ''), 4);
                }
            }
        }

        return [
            'has_data' => true,
            'sufficient_observations' => $sufficient,
            'observations_count' => $obsCount,
            'min_observations_required' => self::MIN_OBSERVATIONS,
            'risk_free_rate_annual' => Dec::round($riskFreeRateAnnual, 6),
            'max_drawdown' => $maxDrawdownPct,
            'max_drawdown_episode' => $maxDdRow,
            'current_drawdown' => $currentDrawdownPct,
            'annualized_volatility' => $annVol,
            'downside_deviation' => $downsideDev,
            'sharpe_ratio' => $sharpe,
            'sortino_ratio' => $sortino,
            'calmar_ratio' => $calmar,
            'var_95_daily_pct' => $var95Pct,
            'var_95_daily_rep' => $var95Rep,
            'cvar_95_daily_pct' => $cvar95Pct,
            'positive_days_pct' => $positiveDaysPct,
            'beta' => $beta,
            'benchmark_correlation' => $correlation,
            'r_squared' => $rSquared,
            'upside_capture' => $upsideCapture,
            'downside_capture' => $downsideCapture,
            'underwater_series' => $underwater,
            'drawdown_episodes' => $formattedEpisodes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(string $riskFreeRateAnnual): array
    {
        return [
            'has_data' => false,
            'sufficient_observations' => false,
            'observations_count' => 0,
            'min_observations_required' => self::MIN_OBSERVATIONS,
            'risk_free_rate_annual' => Dec::round($riskFreeRateAnnual, 6),
            'max_drawdown' => null,
            'max_drawdown_episode' => null,
            'current_drawdown' => null,
            'annualized_volatility' => null,
            'downside_deviation' => null,
            'sharpe_ratio' => null,
            'sortino_ratio' => null,
            'calmar_ratio' => null,
            'var_95_daily_pct' => null,
            'var_95_daily_rep' => null,
            'cvar_95_daily_pct' => null,
            'positive_days_pct' => null,
            'beta' => null,
            'benchmark_correlation' => null,
            'r_squared' => null,
            'upside_capture' => null,
            'downside_capture' => null,
            'underwater_series' => [],
            'drawdown_episodes' => [],
        ];
    }
}
