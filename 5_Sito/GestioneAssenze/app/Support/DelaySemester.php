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

    public static function fromDate(CarbonInterface $referenceDate): self
    {
        $reference = CarbonImmutable::instance($referenceDate)->startOfDay();
        $year = (int) $reference->year;
        $month = (int) $reference->month;

        if ($month >= 8) {
            return new self(
                CarbonImmutable::create($year, 8, 1)->startOfDay(),
                CarbonImmutable::create($year + 1, 1, 31)->endOfDay(),
                sprintf('%d-S1', $year)
            );
        }

        if ($month <= 1) {
            return new self(
                CarbonImmutable::create($year - 1, 8, 1)->startOfDay(),
                CarbonImmutable::create($year, 1, 31)->endOfDay(),
                sprintf('%d-S1', $year - 1)
            );
        }

        return new self(
            CarbonImmutable::create($year, 2, 1)->startOfDay(),
            CarbonImmutable::create($year, 7, 31)->endOfDay(),
            sprintf('%d-S2', $year)
        );
    }
}
