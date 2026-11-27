<?php

namespace App\Domain\MarketData\Services;

use App\Models\Exchange;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class MarketClockService
{
    /**
     * @return array{
     *     state: 'open'|'break'|'closed'|'weekend'|'holiday',
     *     local_time: string,
     *     timezone: string,
     *     next_open_at: string|null,
     *     holiday_name: string|null,
     *     holidays_loaded: bool
     * }
     */
    public function status(Exchange $exchange, ?CarbonInterface $now = null): array
    {
        $utcNow = $now ? CarbonImmutable::instance($now)->utc() : CarbonImmutable::now('UTC');
        $tz = $exchange->timezone ?: 'UTC';
        $localNow = $utcNow->setTimezone($tz);

        $holidays = $exchange->relationLoaded('holidays')
            ? $exchange->holidays
            : $exchange->holidays()->whereYear('date', $localNow->year)->get();

        $holidaysLoaded = $holidays->contains(fn ($h) => (int) $h->date->year === (int) $localNow->year);

        $todayStr = $localNow->toDateString();
        $holidayMap = [];
        foreach ($holidays as $h) {
            $holidayMap[$h->date->toDateString()] = $h;
        }

        $todayHoliday = $holidayMap[$todayStr] ?? null;
        $sessions = $exchange->sessions ?? [];
        $daySessions = $sessions[(string) $localNow->isoWeekday()] ?? [];

        $state = 'closed';
        $holidayName = null;

        if ($todayHoliday && ! $todayHoliday->is_half_day) {
            $state = 'holiday';
            $holidayName = $todayHoliday->name;
        } elseif ($localNow->isWeekend() || empty($daySessions)) {
            $state = 'weekend';
        } else {
            $currentHm = $localNow->format('H:i');
            $inOpen = false;
            $betweenSessions = false;

            foreach ($daySessions as $idx => [$start, $end]) {
                if ($currentHm >= $start && $currentHm < $end) {
                    $inOpen = true;
                    break;
                }
                if (isset($daySessions[$idx + 1])) {
                    $nextStart = $daySessions[$idx + 1][0];
                    if ($currentHm >= $end && $currentHm < $nextStart) {
                        $betweenSessions = true;
                    }
                }
            }

            if ($inOpen) {
                $state = 'open';
            } elseif ($betweenSessions) {
                $state = 'break';
            } else {
                $state = 'closed';
            }
        }

        return [
            'state' => $state,
            'local_time' => $localNow->toIso8601String(),
            'timezone' => $tz,
            'next_open_at' => $this->computeNextOpenAt($localNow, $sessions, $holidayMap),
            'holiday_name' => $holidayName,
            'holidays_loaded' => $holidaysLoaded,
        ];
    }

    /**
     * @param  array<string, list<array{0: string, 1: string}>>  $sessions
     * @param  array<string, \App\Models\MarketHoliday>  $holidayMap
     */
    private function computeNextOpenAt(CarbonImmutable $localNow, array $sessions, array $holidayMap): ?string
    {
        for ($offset = 0; $offset <= 14; $offset++) {
            $day = $localNow->addDays($offset);
            $dayStr = $day->toDateString();

            if ($day->isWeekend()) {
                continue;
            }

            $holiday = $holidayMap[$dayStr] ?? null;
            if ($holiday && ! $holiday->is_half_day) {
                continue;
            }

            $daySessions = $sessions[(string) $day->isoWeekday()] ?? [];
            foreach ($daySessions as [$start, $end]) {
                [$h, $m] = array_map('intval', explode(':', $start));
                $sessionStart = $day->setTime($h, $m, 0);
                if ($sessionStart->greaterThan($localNow)) {
                    return $sessionStart->utc()->toIso8601String();
                }
            }
        }

        return null;
    }
}
