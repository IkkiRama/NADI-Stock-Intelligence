<?php

namespace App\Domain\Performance\Services;

use App\Support\Math\Dec;
use Carbon\CarbonImmutable;

/**
 * Time-Weighted Return (TWR) calculator using geometric subperiod chaining.
 *
 * Convention: external inflows enter at Start-of-Day (`B_t = begin_equity + inflow_rep`)
 * and external outflows exit at End-of-Day (`E_t = end_equity - outflow_rep`, where
 * `outflow_rep <= 0`). Thus external deposits/withdrawals never distort investment returns.
 */
final class TwrCalculator
{
    /**
     * @param  array<string, mixed>  $windowSeries
     * @return array<string, mixed>
     */
    public function calculate(array $windowSeries): array
    {
        $days = $windowSeries['days'] ?? [];
        if (empty($days)) {
            return [
                'has_data' => false,
                'twr_total' => null,
                'twr_annualized' => null,
                'elapsed_days' => 0,
                'short_period_notice' => false,
                'annualization_skipped_reason' => null,
                'flow_timing_convention' => 'inflow_start_of_day_outflow_end_of_day',
                'total_inflows_rep' => '0.0000',
                'total_outflows_rep' => '0.0000',
                'net_external_flows_rep' => '0.0000',
                'net_investment_gain_rep' => '0.0000',
                'daily_series' => [],
                'monthly_returns' => [],
                'annual_returns' => [],
            ];
        }

        $growthFactor = '1.0000000000';
        $totalInflows = '0.0000';
        $totalOutflows = '0.0000';

        $activeStartDate = null;
        $endDate = (string) end($days)['date'];

        $monthFactors = [];
        $yearFactors = [];
        $dailySeries = [];

        foreach ($days as $day) {
            $date = (string) $day['date'];
            $beginEq = (string) $day['begin_equity'];
            $inflow = (string) $day['inflow_rep'];
            $outflow = (string) $day['outflow_rep'];
            $endEq = (string) $day['end_equity'];

            $totalInflows = Dec::add($totalInflows, $inflow, 4);
            $totalOutflows = Dec::add($totalOutflows, $outflow, 4);

            $baseCapital = Dec::add($beginEq, $inflow, 4);
            $endingCapitalBeforeWithdrawal = Dec::sub($endEq, $outflow, 4);

            if (Dec::cmp($baseCapital, '0') > 0 && $activeStartDate === null) {
                $activeStartDate = $date;
            }

            if (Dec::cmp($baseCapital, '0') > 0) {
                $gain = Dec::sub($endingCapitalBeforeWithdrawal, $baseCapital, 4);
                $dailyReturn = Dec::div($gain, $baseCapital, 8) ?? '0.00000000';
                // Floor daily factor at 0 to avoid negative geometric base on total wipeout
                $factor = Dec::max('0.00000000', Dec::add('1', $dailyReturn, 8));
            } else {
                $dailyReturn = '0.00000000';
                $factor = '1.00000000';
            }

            $growthFactor = Dec::mul($growthFactor, $factor, 10);
            $cumTwr = Dec::sub($growthFactor, '1', 8);
            $navIndex = Dec::mul($growthFactor, '100', 4);

            $month = substr($date, 0, 7);
            $year = substr($date, 0, 4);
            $monthFactors[$month] = Dec::mul($monthFactors[$month] ?? '1.0000000000', $factor, 10);
            $yearFactors[$year] = Dec::mul($yearFactors[$year] ?? '1.0000000000', $factor, 10);

            $dailySeries[] = [
                'date' => $date,
                'begin_equity' => $beginEq,
                'end_equity' => $endEq,
                'external_cash_flow' => (string) $day['external_cash_flow'],
                'inflow_rep' => $inflow,
                'outflow_rep' => $outflow,
                'daily_return' => $dailyReturn,
                'cumulative_twr' => $cumTwr,
                'nav_index' => $navIndex,
            ];
        }

        $twrTotal = Dec::sub($growthFactor, '1', 8);
        $netExternalFlows = Dec::add($totalInflows, $totalOutflows, 4);
        $windowBegin = (string) ($windowSeries['begin_equity'] ?? '0.0000');
        $windowEnd = (string) ($windowSeries['end_equity'] ?? '0.0000');
        $netInvestmentGain = Dec::sub(Dec::sub($windowEnd, $windowBegin, 4), $netExternalFlows, 4);

        $elapsedDays = 0;
        if ($activeStartDate !== null) {
            $elapsedDays = max(
                0,
                (int) CarbonImmutable::parse($activeStartDate, 'UTC')
                    ->diffInDays(CarbonImmutable::parse($endDate, 'UTC')),
            );
        }

        $twrAnnualized = null;
        $annualizationSkippedReason = null;
        if ($elapsedDays < 30) {
            $annualizationSkippedReason = 'under_30_days';
        } elseif ((float) $growthFactor <= 0.0) {
            $annualizationSkippedReason = 'non_positive_wealth_factor';
        } else {
            $ann = pow((float) $growthFactor, 365.0 / $elapsedDays) - 1.0;
            if (is_finite($ann) && $ann > -1.0 && $ann < 1000.0) {
                $twrAnnualized = Dec::round(number_format($ann, 10, '.', ''), 8);
            } else {
                $annualizationSkippedReason = 'overflow';
            }
        }

        $monthlyReturns = [];
        foreach ($monthFactors as $month => $mf) {
            $monthlyReturns[] = [
                'period' => $month,
                'twr' => Dec::sub($mf, '1', 8),
            ];
        }

        $annualReturns = [];
        foreach ($yearFactors as $year => $yf) {
            $annualReturns[] = [
                'period' => $year,
                'twr' => Dec::sub($yf, '1', 8),
            ];
        }

        return [
            'has_data' => true,
            'twr_total' => $twrTotal,
            'twr_annualized' => $twrAnnualized,
            'elapsed_days' => $elapsedDays,
            'short_period_notice' => $elapsedDays < 365,
            'annualization_skipped_reason' => $annualizationSkippedReason,
            'flow_timing_convention' => 'inflow_start_of_day_outflow_end_of_day',
            'total_inflows_rep' => $totalInflows,
            'total_outflows_rep' => $totalOutflows,
            'net_external_flows_rep' => $netExternalFlows,
            'net_investment_gain_rep' => $netInvestmentGain,
            'daily_series' => $dailySeries,
            'monthly_returns' => $monthlyReturns,
            'annual_returns' => $annualReturns,
        ];
    }
}
