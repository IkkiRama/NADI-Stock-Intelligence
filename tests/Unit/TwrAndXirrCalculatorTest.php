<?php

namespace Tests\Unit;

use App\Domain\Performance\Services\TwrCalculator;
use App\Domain\Performance\Services\XirrCalculator;
use Tests\TestCase;

class TwrAndXirrCalculatorTest extends TestCase
{
    public function test_twr_is_completely_invariant_to_external_deposit_size_while_xirr_reflects_cash_flow_timing(): void
    {
        $twrCalc = new TwrCalculator();
        $xirrCalc = new XirrCalculator();

        // Day 0 (2025-01-01): Investor deposits 10,000 -> End Equity 10,000
        // Day 1 (2025-07-02): Asset gains +20% -> End Equity 12,000
        // Day 2 (2025-07-03): Investor deposits +90,000 -> 102,000, then drops -5% -> End Equity 96,900
        // Result: TWR = (1.20 * 0.95) - 1 = +14.00%, even though total dollar profit is -3,100 (MWR/XIRR < 0).
        $windowSeries = [
            'has_data' => true,
            'start_date' => '2025-01-01',
            'end_date' => '2025-07-03',
            'reporting_currency' => 'USD',
            'begin_equity' => '0.0000',
            'end_equity' => '96900.0000',
            'days' => [
                [
                    'date' => '2025-01-01',
                    'begin_equity' => '0.0000',
                    'inflow_rep' => '10000.0000',
                    'outflow_rep' => '0.0000',
                    'external_cash_flow' => '10000.0000',
                    'end_equity' => '10000.0000',
                ],
                [
                    'date' => '2025-07-02',
                    'begin_equity' => '10000.0000',
                    'inflow_rep' => '0.0000',
                    'outflow_rep' => '0.0000',
                    'external_cash_flow' => '0.0000',
                    'end_equity' => '12000.0000',
                ],
                [
                    'date' => '2025-07-03',
                    'begin_equity' => '12000.0000',
                    'inflow_rep' => '90000.0000',
                    'outflow_rep' => '0.0000',
                    'external_cash_flow' => '90000.0000',
                    'end_equity' => '96900.0000',
                ],
            ],
        ];

        $twr = $twrCalc->calculate($windowSeries);
        $xirr = $xirrCalc->calculateForWindow($windowSeries);

        $this->assertTrue($twr['has_data']);
        $this->assertEqualsWithDelta(0.14, (float) $twr['twr_total'], 1e-6);
        $this->assertEqualsWithDelta(-3100.0, (float) $twr['net_investment_gain_rep'], 0.01);

        $this->assertSame('converged', $xirr['status']);
        $this->assertNotNull($xirr['xirr_annualized']);
        $this->assertLessThan(0.0, (float) $xirr['xirr_annualized']);
        $this->assertLessThan(0.0, (float) $xirr['modified_dietz_return']);
    }

    public function test_twr_handles_full_end_of_day_withdrawal_without_div_zero_or_gain_loss(): void
    {
        $twrCalc = new TwrCalculator();

        // Day 0: deposit 10,000 -> End 10,000
        // Day 1: grows +10% to 11,000, then investor withdraws all 11,000 -> End Equity 0
        $windowSeries = [
            'has_data' => true,
            'start_date' => '2025-01-01',
            'end_date' => '2025-01-02',
            'reporting_currency' => 'USD',
            'begin_equity' => '0.0000',
            'end_equity' => '0.0000',
            'days' => [
                [
                    'date' => '2025-01-01',
                    'begin_equity' => '0.0000',
                    'inflow_rep' => '10000.0000',
                    'outflow_rep' => '0.0000',
                    'external_cash_flow' => '10000.0000',
                    'end_equity' => '10000.0000',
                ],
                [
                    'date' => '2025-01-02',
                    'begin_equity' => '10000.0000',
                    'inflow_rep' => '0.0000',
                    'outflow_rep' => '-11000.0000',
                    'external_cash_flow' => '-11000.0000',
                    'end_equity' => '0.0000',
                ],
            ],
        ];

        $twr = $twrCalc->calculate($windowSeries);
        $this->assertEqualsWithDelta(0.10, (float) $twr['twr_total'], 1e-6);
        $this->assertNull($twr['twr_annualized']);
        $this->assertSame('under_30_days', $twr['annualization_skipped_reason']);
    }

    public function test_xirr_solves_known_annual_and_irregular_cash_flows_and_guards_edge_cases(): void
    {
        $xirr = new XirrCalculator();

        // Exact 365-day +10.00% fixture
        $annual = $xirr->calculateFromSchedule([
            ['date' => '2025-01-01', 'amount' => -10000],
            ['date' => '2026-01-01', 'amount' => 11000],
        ]);
        $this->assertSame('converged', $annual['status']);
        $this->assertEqualsWithDelta(0.10, (float) $annual['xirr_annualized'], 1e-5);

        // Multi-flow irregular fixture: verify calculated XIRR zeros NPV
        $flows = [
            ['date' => '2025-01-01', 'amount' => -10000],
            ['date' => '2025-04-15', 'amount' => -5000],
            ['date' => '2025-08-01', 'amount' => 2500],
            ['date' => '2025-12-31', 'amount' => 14500],
        ];
        $multi = $xirr->calculateFromSchedule($flows);
        $this->assertSame('converged', $multi['status']);
        $r = (float) $multi['xirr_annualized'];
        $npv = (-10000.0)
            + (-5000.0 / ((1.0 + $r) ** (104.0 / 365.0)))
            + (2500.0 / ((1.0 + $r) ** (212.0 / 365.0)))
            + (14500.0 / ((1.0 + $r) ** (364.0 / 365.0)));
        $this->assertEqualsWithDelta(0.0, $npv, 1e-2);

        // No sign change (all outflows)
        $noSign = $xirr->calculateFromSchedule([
            ['date' => '2025-01-01', 'amount' => -5000],
            ['date' => '2025-06-01', 'amount' => -3000],
        ]);
        $this->assertSame('no_sign_change', $noSign['status']);
        $this->assertNull($noSign['xirr_annualized']);

        // Insufficient flows (single date)
        $single = $xirr->calculateFromSchedule([
            ['date' => '2025-01-01', 'amount' => -5000],
        ]);
        $this->assertSame('insufficient_flows', $single['status']);
        $this->assertNull($single['xirr_annualized']);
    }
}
