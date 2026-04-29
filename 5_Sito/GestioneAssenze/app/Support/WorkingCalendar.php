<?php

namespace App\Support;

use App\Models\SchoolHoliday;
use Carbon\Carbon;

class WorkingCalendar
{
    /**
     * @return array<string,true>
     */
    private static function loadHolidaySet(): array
    {
        return SchoolHoliday::query()
            ->get(['holiday_date'])
            ->reduce(function (array $carry, SchoolHoliday $holiday): array {
                $date = $holiday->holiday_date;
                $dateKey = $date instanceof Carbon
                    ? $date->toDateString()
                    : Carbon::parse((string) $date)->toDateString();
                $carry[$dateKey] = true;

                return $carry;
            }, []);
    }

    /**
     * @param  array<string,true>  $holidaySet
     */
    private static function isWorkingDay(Carbon $date, array $holidaySet): bool
    {
        $dateKey = $date->copy()->startOfDay()->toDateString();

        return ! $date->isWeekend() && ! isset($holidaySet[$dateKey]);
    }

    public static function addBusinessDays(Carbon $startDate, int $days): Carbon
    {
        if ($days <= 0) {
            return $startDate->copy();
        }

        $holidaySet = self::loadHolidaySet();
        $date = $startDate->copy();
        $added = 0;

        while ($added < $days) {
            $date->addDay();
            if (! self::isWorkingDay($date, $holidaySet)) {
                continue;
            }

            $added++;
        }

        return $date;
    }

    public static function businessDaysUntil(Carbon $fromDate, Carbon $toDate): int
    {
        $from = $fromDate->copy()->startOfDay();
        $to = $toDate->copy()->startOfDay();

        if ($to->lt($from)) {
            return -self::businessDaysUntil($to, $from);
        }

        $holidaySet = self::loadHolidaySet();
        $days = 0;
        $cursor = $from->copy();

        while ($cursor->lt($to)) {
            $cursor->addDay();
            if (! self::isWorkingDay($cursor, $holidaySet)) {
                continue;
            }

            $days++;
        }

        return $days;
    }

    public static function workingMinutesBetween(Carbon $fromDateTime, Carbon $toDateTime): int
    {
        $from = $fromDateTime->copy();
        $to = $toDateTime->copy();

        if ($to->lte($from)) {
            return 0;
        }

        $holidaySet = self::loadHolidaySet();
        $minutes = 0;
        $cursor = $from->copy();

        while ($cursor->lt($to)) {
            $nextDayStart = $cursor->copy()->startOfDay()->addDay();
            $segmentEnd = $to->lt($nextDayStart)
                ? $to->copy()
                : $nextDayStart;
            $segmentDay = $cursor->copy()->startOfDay();

            if (self::isWorkingDay($segmentDay, $holidaySet)) {
                $minutes += $cursor->diffInMinutes($segmentEnd);
            }

            $cursor = $segmentEnd;
        }

        return max($minutes, 0);
    }
}
