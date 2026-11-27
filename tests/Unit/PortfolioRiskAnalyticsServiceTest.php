<?php

namespace Tests\Unit;

use App\Domain\Risk\Services\PortfolioRiskAnalyticsService;
use Tests\TestCase;

class PortfolioRiskAnalyticsServiceTest extends TestCase
{
    public function test_drawdown_tracks_exact_peak_trough_recovery_and_underwater_episodes(): void
    {
        $svc = new PortfolioRiskAnalyticsService();

        // 100 -> 120 (+20%, peak) -> 90 (-25% from peak, trough) -> 126 (recovered)
        // Followed by second ongoing drop: 126 -> 113.4 (-10% underwater)
        $twr = [
            'twr_total' => '0.13400000',
            'twr_annualized' => null,
            'daily_series' => [
                ['date' => '2025-01-01', 'daily_return' => '0.00000000', 'cumulative_twr' => '0.00000000'],
                ['date' => '2025-01-10', 'daily_return' => '0.20000000', 'cumulative_twr' => '0.20000000'],
                ['date' => '2025-01-20', 'daily_return' => '-0.25000000', 'cumulative_twr' => '-0.10000000'],
                ['date' => '2025-02-01', 'daily_return' => '0.40000000', 'cumulative_twr' => '0.26000000'],
                ['date' => '2025-02-10', 'daily_return' => '-0.10000000', 'cumulative_twr' => '0.13400000'],
            ],
        ];

        $res = $svc->analyze([], $twr, ['observations_count' => 4, 'series' => []], '100000000', '0.0600');

        $this->assertTrue($res['has_data']);
        $this->assertEqualsWithDelta(-0.25, (float) $res['max_drawdown'], 1e-6);
        $this->assertSame('2025-01-10', $res['max_drawdown_episode']['peak_date']);
        $this->assertSame('2025-01-20', $res['max_drawdown_episode']['trough_date']);
        $this->assertSame('2025-02-01', $res['max_drawdown_episode']['recovery_date']);
        $this->assertSame('recovered', $res['max_drawdown_episode']['status']);

        $this->assertEqualsWithDelta(-0.10, (float) $res['current_drawdown'], 1e-6);
        $this->assertCount(2, $res['drawdown_episodes']);
        $this->assertSame('underwater', $res['drawdown_episodes'][1]['status']);
        $this->assertNull($res['drawdown_episodes'][1]['recovery_date']);

        // Under 20 observations -> volatility, Sharpe, Sortino, VaR, Beta must stay null
        $this->assertFalse($res['sufficient_observations']);
        $this->assertNull($res['annualized_volatility']);
        $this->assertNull($res['sharpe_ratio']);
        $this->assertNull($res['sortino_ratio']);
        $this->assertNull($res['var_95_daily_pct']);
        $this->assertNull($res['beta']);
    }

    public function test_computes_volatility_sharpe_sortino_var_and_beta_when_observations_meet_minimum(): void
    {
        $svc = new PortfolioRiskAnalyticsService();

        $dailySeries = [];
        $benchSeries = [];
        $cumPort = 1.0;
        $cumBench = 1.0;

        for ($i = 0; $i < 30; $i++) {
            $d = sprintf('2025-03-%02d', $i + 1);
            $rB = ($i % 2 === 0) ? 0.010 : -0.004;
            $rP = ($i % 2 === 0) ? 0.012 : -0.005; // 1.2x+ beta-like tracking
            if ($i > 0) {
                $cumPort *= (1.0 + $rP);
                $cumBench *= (1.0 + $rB);
            }

            $dailySeries[] = [
                'date' => $d,
                'daily_return' => $i === 0 ? '0.00000000' : number_format($rP, 8, '.', ''),
                'cumulative_twr' => number_format($cumPort - 1.0, 8, '.', ''),
            ];
            $benchSeries[] = [
                'date' => $d,
                'portfolio_index' => number_format($cumPort * 100.0, 4, '.', ''),
                'benchmark_index' => number_format($cumBench * 100.0, 4, '.', ''),
            ];
        }

        $twr = [
            'twr_total' => number_format($cumPort - 1.0, 8, '.', ''),
            'twr_annualized' => '0.25000000',
            'daily_series' => $dailySeries,
        ];
        $benchCmp = [
            'observations_count' => 29,
            'series' => $benchSeries,
        ];

        $res = $svc->analyze([], $twr, $benchCmp, '100000000', '0.0600');

        $this->assertTrue($res['sufficient_observations']);
        $this->assertGreaterThan(0.0, (float) $res['annualized_volatility']);
        $this->assertGreaterThan(0.0, (float) $res['downside_deviation']);
        $this->assertNotNull($res['sharpe_ratio']);
        $this->assertNotNull($res['sortino_ratio']);
        $this->assertNotNull($res['calmar_ratio']);
        $this->assertLessThan(0.0, (float) $res['var_95_daily_pct']);
        $this->assertGreaterThan(0.0, (float) $res['var_95_daily_rep']);
        $this->assertEqualsWithDelta(1.21, (float) $res['beta'], 0.05);
        $this->assertEqualsWithDelta(1.0, (float) $res['benchmark_correlation'], 0.01);
    }
}
