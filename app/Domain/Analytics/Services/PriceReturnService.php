<?php

namespace App\Domain\Analytics\Services;

use App\Support\Math\Dec;
use Carbon\CarbonImmutable;

final class PriceReturnService
{
    /**
     * Compute period return as a decimal fraction string (e.g. "0.10000000" for +10%).
     * Returns null when history does not reach the anchor date or anchor close is zero.
     *
     * @param  array<string, string>  $closesByDate  Map of YYYY-MM-DD => close price string
     */
    public function periodReturn(array $closesByDate, string $period): ?string
    {
        if (count($closesByDate) < 2) {
            return null;
        }

        ksort($closesByDate);
        $dates = array_keys($closesByDate);
        $latestDate = end($dates);
        $latestClose = $closesByDate[$latestDate];

        $period = strtoupper($period);
        if ($period === '1D') {
            $prevDate = $dates[count($dates) - 2];

            return Dec::pctChange($latestClose, $closesByDate[$prevDate], 8);
        }

        $latest = CarbonImmutable::parse($latestDate, 'UTC');
        $anchor = match ($period) {
            '1W' => $latest->subWeek(),
            '1M' => $latest->subMonthNoOverflow(),
            '3M' => $latest->subMonthsNoOverflow(3),
            '6M' => $latest->subMonthsNoOverflow(6),
            '1Y' => $latest->subYear(),
            'YTD' => CarbonImmutable::create($latest->year - 1, 12, 31, 0, 0, 0, 'UTC'),
            default => null,
        };

        if ($anchor === null) {
            return null;
        }

        $anchorStr = $anchor->toDateString();
        $anchorClose = null;
        foreach ($closesByDate as $date => $close) {
            if ($date <= $anchorStr) {
                $anchorClose = $close;
            } else {
                break;
            }
        }

        if ($anchorClose === null) {
            return null;
        }

        return Dec::pctChange($latestClose, $anchorClose, 8);
    }

    /**
     * @param  array<string, string>  $highsByDate
     */
    public function high52w(array $highsByDate): ?string
    {
        if (empty($highsByDate)) {
            return null;
        }

        ksort($highsByDate);
        $slice = array_slice($highsByDate, -252, null, true);
        $max = null;
        foreach ($slice as $val) {
            $max = $max === null ? (string) Dec::of($val) : Dec::max($max, $val);
        }

        return $max;
    }

    /**
     * @param  array<string, string>  $lowsByDate
     */
    public function low52w(array $lowsByDate): ?string
    {
        if (empty($lowsByDate)) {
            return null;
        }

        ksort($lowsByDate);
        $slice = array_slice($lowsByDate, -252, null, true);
        $min = null;
        foreach ($slice as $val) {
            $min = $min === null ? (string) Dec::of($val) : Dec::min($min, $val);
        }

        return $min;
    }

    /**
     * @param  list<int|string>  $volumes
     */
    public function avgVolume(array $volumes, int $days = 20): ?string
    {
        if (empty($volumes)) {
            return null;
        }

        $slice = array_slice($volumes, -$days);
        $sum = '0';
        foreach ($slice as $vol) {
            $sum = Dec::add($sum, (string) $vol);
        }

        return Dec::div($sum, (string) count($slice), 2);
    }
}
