<?php

namespace App\Domain\Analytics\Services;

use App\Models\CorporateAction;
use App\Models\Instrument;
use App\Models\ScoringConfig;
use App\Support\Math\Dec;
use Carbon\CarbonImmutable;

final class StockScoringService
{
    public const DEFAULT_WEIGHTS = [
        'fundamentals' => 30,
        'valuation' => 25,
        'technical' => 20,
        'risk' => 15,
        'catalysts' => 10,
    ];

    /**
     * Score an instrument (0-100) with transparent component breakdown and missing-data coverage.
     *
     * @param  array<string, mixed>|null  $fundamentals
     * @param  array<string, mixed>|null  $technicals
     * @param  array{per?: float|null, pbv?: float|null, roe?: float|null, net_margin?: float|null}  $sectorMedians
     * @return array<string, mixed>
     */
    public function scoreInstrument(
        Instrument $instrument,
        ?string $closePrice,
        ?array $fundamentals,
        ?array $technicals,
        string $asOfDate,
        array $sectorMedians = [],
        ?ScoringConfig $config = null,
    ): array {
        $weights = $config?->weights ?? self::DEFAULT_WEIGHTS;
        $version = $config?->version ?? 'v1.0';

        $missing = [];
        $reasons = [];

        // 1. Fundamentals (0-100)
        $fundPts = [];
        if ($fundamentals) {
            if ($fundamentals['roe'] !== null) {
                $roe = (float) $fundamentals['roe'];
                $fundPts[] = $this->clamp(($roe / 0.25) * 100.0);
                if ($roe >= 0.15) {
                    $reasons[] = ['type' => 'positive', 'label' => 'ROE kuat', 'detail' => sprintf('ROE %.1f%%', $roe * 100)];
                } elseif ($roe < 0.05) {
                    $reasons[] = ['type' => 'negative', 'label' => 'ROE rendah', 'detail' => sprintf('ROE %.1f%%', $roe * 100)];
                }
            } else {
                $missing[] = 'roe';
            }

            if ($fundamentals['net_margin'] !== null) {
                $nm = (float) $fundamentals['net_margin'];
                $fundPts[] = $this->clamp(($nm / 0.25) * 100.0);
            } else {
                $missing[] = 'net_margin';
            }

            if ($fundamentals['revenue_growth_yoy'] !== null) {
                $rg = (float) $fundamentals['revenue_growth_yoy'];
                $fundPts[] = $this->clamp(50.0 + ($rg / 0.30) * 50.0);
                if ($rg >= 0.10) {
                    $reasons[] = ['type' => 'positive', 'label' => 'Pertumbuhan pendapatan solid', 'detail' => sprintf('YoY %+.1f%%', $rg * 100)];
                } elseif ($rg < 0) {
                    $reasons[] = ['type' => 'negative', 'label' => 'Kontraksi pendapatan', 'detail' => sprintf('YoY %+.1f%%', $rg * 100)];
                }
            } else {
                $missing[] = 'revenue_growth_yoy';
            }

            if ($fundamentals['earnings_growth_yoy'] !== null) {
                $eg = (float) $fundamentals['earnings_growth_yoy'];
                $fundPts[] = $this->clamp(50.0 + ($eg / 0.35) * 50.0);
            }
        } else {
            $missing[] = 'financial_statements';
        }
        $fundScore = ! empty($fundPts) ? array_sum($fundPts) / count($fundPts) : null;

        // 2. Valuation (0-100) relative to sector medians & sensible bounds
        $valPts = [];
        if ($fundamentals) {
            if ($fundamentals['per'] !== null) {
                $per = (float) $fundamentals['per'];
                $medPer = $sectorMedians['per'] ?? 15.0;
                $ratio = $medPer > 0 ? $per / $medPer : 1.0;
                $valPts[] = $this->clamp(100.0 - (($ratio - 0.5) * 60.0));
                if ($per <= $medPer * 0.85) {
                    $reasons[] = ['type' => 'positive', 'label' => 'PER di bawah median sektor', 'detail' => sprintf('PER %.1fx', $per)];
                } elseif ($per >= $medPer * 1.4) {
                    $reasons[] = ['type' => 'negative', 'label' => 'PER relatif mahal', 'detail' => sprintf('PER %.1fx', $per)];
                }
            } else {
                $missing[] = 'per';
            }

            if ($fundamentals['pbv'] !== null) {
                $pbv = (float) $fundamentals['pbv'];
                $medPbv = $sectorMedians['pbv'] ?? 2.0;
                $ratio = $medPbv > 0 ? $pbv / $medPbv : 1.0;
                $valPts[] = $this->clamp(100.0 - (($ratio - 0.5) * 55.0));
            } else {
                $missing[] = 'pbv';
            }

            if ($fundamentals['dividend_yield'] !== null) {
                $dy = (float) $fundamentals['dividend_yield'];
                $valPts[] = $this->clamp(($dy / 0.06) * 100.0);
            }
        }
        $valScore = ! empty($valPts) ? array_sum($valPts) / count($valPts) : null;

        // 3. Technical strength (0-100)
        $techPts = [];
        if ($technicals && $closePrice !== null) {
            $c = (float) $closePrice;
            if ($technicals['sma_50'] !== null) {
                $above50 = $c >= (float) $technicals['sma_50'];
                $techPts[] = $above50 ? 78.0 : 28.0;
                $reasons[] = [
                    'type' => $above50 ? 'positive' : 'negative',
                    'label' => $above50 ? 'Harga di atas SMA 50' : 'Harga di bawah SMA 50',
                    'detail' => sprintf('Close %.2f vs SMA50 %.2f', $c, (float) $technicals['sma_50']),
                ];
            } else {
                $missing[] = 'sma_50';
            }

            if ($technicals['sma_200'] !== null) {
                $techPts[] = $c >= (float) $technicals['sma_200'] ? 82.0 : 25.0;
            } else {
                $missing[] = 'sma_200';
            }

            if ($technicals['rsi_14'] !== null) {
                $rsi = (float) $technicals['rsi_14'];
                if ($rsi >= 45 && $rsi <= 68) {
                    $techPts[] = 82.0;
                } elseif ($rsi > 75) {
                    $techPts[] = 42.0;
                } elseif ($rsi < 30) {
                    $techPts[] = 48.0;
                } else {
                    $techPts[] = 58.0;
                }
            } else {
                $missing[] = 'rsi_14';
            }

            if ($technicals['relative_strength_1m'] !== null) {
                $rs = (float) $technicals['relative_strength_1m'];
                $techPts[] = $this->clamp(50.0 + ($rs / 0.10) * 50.0);
            }
        } else {
            $missing[] = 'technicals';
        }
        $techScore = ! empty($techPts) ? array_sum($techPts) / count($techPts) : null;

        // 4. Risk stability (0-100: lower volatility & moderate leverage score higher)
        $riskPts = [];
        if ($technicals && $technicals['volatility_20d'] !== null) {
            $vol = (float) $technicals['volatility_20d'];
            $riskPts[] = $this->clamp(100.0 - (($vol - 0.15) / 0.45) * 80.0);
            if ($vol > 0.45) {
                $reasons[] = ['type' => 'negative', 'label' => 'Volatilitas 20H tinggi', 'detail' => sprintf('%.1f%% annualized', $vol * 100)];
            }
        } else {
            $missing[] = 'volatility_20d';
        }

        if ($fundamentals && $fundamentals['der'] !== null) {
            $der = (float) $fundamentals['der'];
            $cap = $fundamentals['is_financial_sector'] ? 12.0 : 2.5;
            $riskPts[] = $this->clamp(100.0 - ($der / $cap) * 80.0);
        }
        $riskScore = ! empty($riskPts) ? array_sum($riskPts) / count($riskPts) : null;

        // 5. Catalysts (0-100: earnings momentum + recent/upcoming corporate actions)
        $catPts = [];
        $recentActionsCount = CorporateAction::query()
            ->where('instrument_id', $instrument->id)
            ->whereDate('ex_date', '>=', CarbonImmutable::parse($asOfDate, 'UTC')->subDays(180)->toDateString())
            ->whereDate('ex_date', '<=', CarbonImmutable::parse($asOfDate, 'UTC')->addDays(90)->toDateString())
            ->count();

        if ($recentActionsCount > 0) {
            $catPts[] = min(90.0, 55.0 + ($recentActionsCount * 15.0));
            $reasons[] = ['type' => 'positive', 'label' => 'Aksi korporasi tercatat', 'detail' => "{$recentActionsCount} event"];
        } else {
            $catPts[] = 45.0;
        }

        if ($fundamentals && $fundamentals['earnings_growth_yoy'] !== null) {
            $eg = (float) $fundamentals['earnings_growth_yoy'];
            $catPts[] = $this->clamp(50.0 + ($eg / 0.30) * 50.0);
        }
        $catScore = ! empty($catPts) ? array_sum($catPts) / count($catPts) : null;

        $componentMap = [
            'fundamentals' => $fundScore,
            'valuation' => $valScore,
            'technical' => $techScore,
            'risk' => $riskScore,
            'catalysts' => $catScore,
        ];

        $totalPossibleWeight = 0.0;
        $coveredWeight = 0.0;
        $weightedSum = 0.0;

        foreach ($componentMap as $key => $score) {
            $w = (float) ($weights[$key] ?? 0);
            $totalPossibleWeight += $w;
            if ($score !== null && $w > 0) {
                $coveredWeight += $w;
                $weightedSum += $score * $w;
            }
        }

        $overall = $coveredWeight > 0 ? $weightedSum / $coveredWeight : 0.0;
        $coverage = $totalPossibleWeight > 0 ? ($coveredWeight / $totalPossibleWeight) * 100.0 : 0.0;

        $fmtScore = fn (?float $s): ?string => $s === null ? null : Dec::round(number_format($this->clamp($s), 4, '.', ''), 2);

        return [
            'instrument_id' => $instrument->id,
            'as_of_date' => $asOfDate,
            'overall_score' => Dec::round(number_format($this->clamp($overall), 4, '.', ''), 2),
            'fundamentals_score' => $fmtScore($fundScore),
            'valuation_score' => $fmtScore($valScore),
            'technical_score' => $fmtScore($techScore),
            'risk_score' => $fmtScore($riskScore),
            'catalysts_score' => $fmtScore($catScore),
            'sector_percentile' => null,
            'data_coverage' => Dec::round(number_format($this->clamp($coverage), 4, '.', ''), 2),
            'reasons' => array_slice($reasons, 0, 8),
            'missing_indicators' => array_values(array_unique($missing)),
            'weights_used' => $weights,
            'calculation_version' => $version,
            'is_demo' => (bool) ($fundamentals['is_demo'] ?? config('nadi.market_data.demo.enabled', true)),
            'calculated_at' => now('UTC'),
        ];
    }

    private function clamp(float $value, float $min = 0.0, float $max = 100.0): float
    {
        return max($min, min($max, $value));
    }
}
