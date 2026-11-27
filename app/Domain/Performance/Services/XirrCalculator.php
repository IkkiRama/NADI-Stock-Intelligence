<?php

namespace App\Domain\Performance\Services;

use App\Support\Math\Dec;
use Carbon\CarbonImmutable;

/**
 * Money-Weighted Return (XIRR) & Modified Dietz calculator using safeguarded
 * Newton-Raphson with bounded bisection fallback and edge-case protection.
 *
 * Cash-flow convention (investor perspective):
 * - Capital committed into portfolio (beginning equity, cash deposit, transfer-in): NEGATIVE
 * - Capital returned to investor (cash withdrawal, transfer-out, terminal equity): POSITIVE
 */
final class XirrCalculator
{
    private const MIN_RATE = -0.9999;

    private const MAX_RATE = 100.0;

    /**
     * Compute window MWR (Modified Dietz) & annualized XIRR from a NAV window.
     *
     * @param  array<string, mixed>  $windowSeries
     * @return array<string, mixed>
     */
    public function calculateForWindow(array $windowSeries): array
    {
        $days = $windowSeries['days'] ?? [];
        if (empty($days)) {
            return $this->emptyResult('insufficient_flows');
        }

        $startDate = (string) $windowSeries['start_date'];
        $endDate = (string) $windowSeries['end_date'];
        $beginEquity = (string) ($windowSeries['begin_equity'] ?? '0.0000');
        $endEquity = (string) ($windowSeries['end_equity'] ?? '0.0000');

        $flows = [];
        if (Dec::cmp($beginEquity, '0') > 0) {
            $flows[] = [
                'date' => $startDate,
                'amount' => Dec::mul($beginEquity, '-1', 4),
            ];
        }

        $netExternalFlows = '0.0000';
        foreach ($days as $day) {
            $ext = (string) $day['external_cash_flow'];
            if (Dec::cmp($ext, '0') !== 0) {
                $flows[] = [
                    'date' => (string) $day['date'],
                    'amount' => Dec::mul($ext, '-1', 4),
                ];
                $netExternalFlows = Dec::add($netExternalFlows, $ext, 4);
            }
        }

        if (Dec::cmp($endEquity, '0') !== 0) {
            $flows[] = [
                'date' => $endDate,
                'amount' => $endEquity,
            ];
        }

        $netGain = Dec::sub(Dec::sub($endEquity, $beginEquity, 4), $netExternalFlows, 4);
        $modifiedDietz = $this->computeModifiedDietz($windowSeries, $netGain);
        $xirrResult = $this->calculateFromSchedule($flows);
        $xirrResult['modified_dietz_return'] = $modifiedDietz;
        $xirrResult['net_investment_gain_rep'] = $netGain;

        return $xirrResult;
    }

    /**
     * Solve annualized XIRR from an explicit investor cash-flow schedule.
     *
     * @param  list<array{date: string, amount: string|int|float}>  $datedFlows
     * @return array<string, mixed>
     */
    public function calculateFromSchedule(array $datedFlows): array
    {
        // Aggregate same-day cash flows so dates represent unique timing buckets
        $byDate = [];
        foreach ($datedFlows as $flow) {
            $d = substr((string) $flow['date'], 0, 10);
            $amt = (float) $flow['amount'];
            if (abs($amt) < 1e-9) {
                continue;
            }
            $byDate[$d] = ($byDate[$d] ?? 0.0) + $amt;
        }

        $normalized = [];
        foreach ($byDate as $d => $amt) {
            if (abs($amt) >= 1e-6) {
                $normalized[$d] = $amt;
            }
        }
        ksort($normalized);

        if (count($normalized) < 2) {
            return $this->emptyResult('insufficient_flows', count($normalized));
        }

        $hasPos = false;
        $hasNeg = false;
        $absSum = 0.0;
        foreach ($normalized as $amt) {
            if ($amt > 0) {
                $hasPos = true;
            } elseif ($amt < 0) {
                $hasNeg = true;
            }
            $absSum += abs($amt);
        }

        if (! $hasPos || ! $hasNeg) {
            return $this->emptyResult('no_sign_change', count($normalized));
        }

        $dates = array_keys($normalized);
        $d0 = CarbonImmutable::parse($dates[0], 'UTC');
        $dEnd = CarbonImmutable::parse(end($dates), 'UTC');
        $elapsedDays = max(0, (int) $d0->diffInDays($dEnd));

        if ($elapsedDays <= 0) {
            return $this->emptyResult('insufficient_flows', count($normalized), 0);
        }

        $points = [];
        foreach ($normalized as $d => $amt) {
            $years = abs((float) $d0->diffInDays(CarbonImmutable::parse($d, 'UTC'))) / 365.0;
            $points[] = ['t' => $years, 'c' => $amt];
        }

        $brackets = $this->findBrackets($points);
        $multipleRoots = count($brackets) > 1;
        $tolerance = 1e-8;
        $residualTolerance = max(1e-6, 1e-8 * $absSum);

        // Try safeguarded Newton-Raphson first
        $solved = $this->solveNewton($points, 0.10, $tolerance, $residualTolerance);

        // Fallback to bisection on the first sign-change bracket closest to 0
        if ($solved === null && ! empty($brackets)) {
            usort($brackets, fn ($a, $b) => abs($a[0] + $a[1]) <=> abs($b[0] + $b[1]));
            $solved = $this->solveBisection($points, $brackets[0][0], $brackets[0][1], $tolerance, $residualTolerance);
        }

        if ($solved === null) {
            return [
                'has_data' => true,
                'xirr_annualized' => null,
                'modified_dietz_return' => null,
                'net_investment_gain_rep' => '0.0000',
                'status' => 'non_convergent',
                'iterations' => $solved['iterations'] ?? 0,
                'cash_flows_count' => count($points),
                'elapsed_days' => $elapsedDays,
                'short_horizon_warning' => $elapsedDays < 30,
                'multiple_roots_warning' => $multipleRoots,
            ];
        }

        return [
            'has_data' => true,
            'xirr_annualized' => Dec::round(number_format($solved['rate'], 10, '.', ''), 8),
            'modified_dietz_return' => null,
            'net_investment_gain_rep' => '0.0000',
            'status' => 'converged',
            'iterations' => $solved['iterations'],
            'cash_flows_count' => count($points),
            'elapsed_days' => $elapsedDays,
            'short_horizon_warning' => $elapsedDays < 30,
            'multiple_roots_warning' => $multipleRoots,
        ];
    }

    /**
     * @param  array<string, mixed>  $windowSeries
     */
    private function computeModifiedDietz(array $windowSeries, string $netGain): ?string
    {
        $days = $windowSeries['days'] ?? [];
        if (empty($days)) {
            return null;
        }

        $startDate = (string) $windowSeries['start_date'];
        $endDate = (string) $windowSeries['end_date'];
        $beginEquity = (string) ($windowSeries['begin_equity'] ?? '0.0000');

        $startObj = CarbonImmutable::parse($startDate, 'UTC');
        $endObj = CarbonImmutable::parse($endDate, 'UTC');
        $totalDays = max(1, (int) $startObj->diffInDays($endObj) + 1);

        $weightedCf = '0.00000000';
        foreach ($days as $day) {
            $inflow = (string) $day['inflow_rep'];
            $outflow = (string) $day['outflow_rep'];
            $offset = (int) $startObj->diffInDays(CarbonImmutable::parse((string) $day['date'], 'UTC'));

            if (Dec::cmp($inflow, '0') !== 0) {
                $wIn = Dec::div((string) max(0, $totalDays - $offset), (string) $totalDays, 8) ?? '0';
                $weightedCf = Dec::add($weightedCf, Dec::mul($inflow, $wIn, 8), 8);
            }
            if (Dec::cmp($outflow, '0') !== 0) {
                $wOut = Dec::div((string) max(0, $totalDays - ($offset + 1)), (string) $totalDays, 8) ?? '0';
                $weightedCf = Dec::add($weightedCf, Dec::mul($outflow, $wOut, 8), 8);
            }
        }

        $avgCap = Dec::add($beginEquity, $weightedCf, 4);
        if (Dec::cmp($avgCap, '0') <= 0) {
            return null;
        }

        return Dec::div($netGain, $avgCap, 8);
    }

    /**
     * @param  list<array{t: float, c: float}>  $points
     * @return array{rate: float, iterations: int}|null
     */
    private function solveNewton(array $points, float $guess, float $tol, float $residualTol): ?array
    {
        $r = $guess;
        for ($i = 1; $i <= 100; $i++) {
            [$npv, $dNpv] = $this->evaluateNpvAndDerivative($points, $r);
            if (! is_finite($npv) || ! is_finite($dNpv)) {
                return null;
            }
            if (abs($npv) <= $residualTol) {
                return ['rate' => $r, 'iterations' => $i];
            }
            if (abs($dNpv) < 1e-12) {
                return null;
            }

            $next = $r - ($npv / $dNpv);
            if ($next <= self::MIN_RATE || $next >= self::MAX_RATE || ! is_finite($next)) {
                return null;
            }
            if (abs($next - $r) < $tol && abs($this->evaluateNpv($points, $next)) <= $residualTol) {
                return ['rate' => $next, 'iterations' => $i];
            }
            $r = $next;
        }

        return null;
    }

    /**
     * @param  list<array{t: float, c: float}>  $points
     * @return array{rate: float, iterations: int}|null
     */
    private function solveBisection(array $points, float $low, float $high, float $tol, float $residualTol): ?array
    {
        $fLow = $this->evaluateNpv($points, $low);
        $fHigh = $this->evaluateNpv($points, $high);
        if (! is_finite($fLow) || ! is_finite($fHigh) || $fLow * $fHigh > 0) {
            return null;
        }

        for ($i = 1; $i <= 120; $i++) {
            $mid = 0.5 * ($low + $high);
            $fMid = $this->evaluateNpv($points, $mid);
            if (! is_finite($fMid)) {
                return null;
            }
            if (abs($fMid) <= $residualTol || abs($high - $low) < $tol) {
                return ['rate' => $mid, 'iterations' => $i];
            }
            if ($fLow * $fMid <= 0) {
                $high = $mid;
                $fHigh = $fMid;
            } else {
                $low = $mid;
                $fLow = $fMid;
            }
        }

        $mid = 0.5 * ($low + $high);
        $fMid = $this->evaluateNpv($points, $mid);

        return abs($fMid) <= $residualTol ? ['rate' => $mid, 'iterations' => 120] : null;
    }

    /**
     * @param  list<array{t: float, c: float}>  $points
     * @return list<array{0: float, 1: float}>
     */
    private function findBrackets(array $points): array
    {
        $grid = [-0.999, -0.95, -0.9, -0.75, -0.5, -0.25, -0.1, 0.0, 0.1, 0.25, 0.5, 1.0, 2.0, 5.0, 10.0, 25.0, 50.0, 100.0];
        $brackets = [];
        $prevR = $grid[0];
        $prevF = $this->evaluateNpv($points, $prevR);

        for ($i = 1; $i < count($grid); $i++) {
            $r = $grid[$i];
            $f = $this->evaluateNpv($points, $r);
            if (is_finite($prevF) && is_finite($f)) {
                if ($prevF === 0.0) {
                    $brackets[] = [$prevR, $prevR];
                } elseif ($prevF * $f <= 0) {
                    $brackets[] = [$prevR, $r];
                }
            }
            $prevR = $r;
            $prevF = $f;
        }

        return $brackets;
    }

    /**
     * @param  list<array{t: float, c: float}>  $points
     */
    private function evaluateNpv(array $points, float $rate): float
    {
        $base = 1.0 + $rate;
        if ($base <= 0.0) {
            return NAN;
        }
        $sum = 0.0;
        foreach ($points as $p) {
            $sum += $p['c'] * ($base ** (-$p['t']));
        }

        return $sum;
    }

    /**
     * @param  list<array{t: float, c: float}>  $points
     * @return array{0: float, 1: float}
     */
    private function evaluateNpvAndDerivative(array $points, float $rate): array
    {
        $base = 1.0 + $rate;
        if ($base <= 0.0) {
            return [NAN, NAN];
        }
        $npv = 0.0;
        $dNpv = 0.0;
        foreach ($points as $p) {
            $disc = $base ** (-$p['t']);
            $npv += $p['c'] * $disc;
            if ($p['t'] > 0.0) {
                $dNpv -= $p['t'] * $p['c'] * ($base ** (-$p['t'] - 1.0));
            }
        }

        return [$npv, $dNpv];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(string $status, int $count = 0, int $elapsedDays = 0): array
    {
        return [
            'has_data' => false,
            'xirr_annualized' => null,
            'modified_dietz_return' => null,
            'net_investment_gain_rep' => '0.0000',
            'status' => $status,
            'iterations' => 0,
            'cash_flows_count' => $count,
            'elapsed_days' => $elapsedDays,
            'short_horizon_warning' => $elapsedDays > 0 && $elapsedDays < 30,
            'multiple_roots_warning' => false,
        ];
    }
}
