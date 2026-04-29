<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class DelaySemester
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $key
    ) {}

    public static function current(): self
    {
        return self::fromDate(now());
    }

    public static function fromDate(
        CarbonInterface $referenceDate,
        int $firstSemesterEndMonth = 1,
        int $firstSemesterEndDay = 26
    ): self
    {
        $reference = CarbonImmutable::instance($referenceDate)->startOfDay();
        $schoolYearStartYear = (int) ($reference->month >= 8 ? $reference->year : $reference->year - 1);
        $schoolYearStart = CarbonImmutable::create($schoolYearStartYear, 8, 1)->startOfDay();
        $schoolYearEnd = CarbonImmutable::create($schoolYearStartYear + 1, 7, 31)->endOfDay();
        $firstSemesterEndYear = $firstSemesterEndMonth >= 8
            ? $schoolYearStartYear
            : $schoolYearStartYear + 1;
        $monthStart = CarbonImmutable::create($firstSemesterEndYear, $firstSemesterEndMonth, 1)->startOfDay();
        $resolvedFirstSemesterEndDay = min($firstSemesterEndDay, $monthStart->daysInMonth);
        $firstSemesterEnd = CarbonImmutable::create(
            $firstSemesterEndYear,
            $firstSemesterEndMonth,
            $resolvedFirstSemesterEndDay
        )->endOfDay();

        if ($reference->lessThanOrEqualTo($firstSemesterEnd)) {
            return new self(
                $schoolYearStart,
                $firstSemesterEnd,
                sprintf('%d-S1', $schoolYearStartYear)
            );
        }

        $secondSemesterStart = $firstSemesterEnd->addDay()->startOfDay();

        return new self(
            $secondSemesterStart,
            $schoolYearEnd,
            sprintf('%d-S2', $secondSemesterStart->year)
        );
    }
}
