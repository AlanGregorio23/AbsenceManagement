<?php

namespace App\Support;

class DeadlineWarningProgress
{
    public const DEFAULT_WARNING_PERCENT = 80;

    public static function normalizePercent(?int $percent, int $default = self::DEFAULT_WARNING_PERCENT): int
    {
        $resolvedDefault = min(max($default, 1), 100);
        $resolvedPercent = $percent ?? $resolvedDefault;

        return min(max((int) $resolvedPercent, 1), 100);
    }

    public static function warningRemainingBusinessDays(
        int $totalBusinessDays,
        ?int $warningPercent
    ): ?int {
        if ($totalBusinessDays <= 0) {
            return null;
        }

        $normalizedPercent = static::normalizePercent($warningPercent);
        $remainingDays = (int) ceil($totalBusinessDays * max(100 - $normalizedPercent, 0) / 100);

        if ($totalBusinessDays > 1) {
            $remainingDays = max($remainingDays, 1);
        }

        return min(max($remainingDays, 0), $totalBusinessDays);
    }

    public static function shouldNotify(
        int $totalBusinessDays,
        int $remainingBusinessDays,
        ?int $warningPercent
    ): bool {
        if ($remainingBusinessDays <= 0) {
            return false;
        }

        $warningRemainingDays = static::warningRemainingBusinessDays(
            $totalBusinessDays,
            $warningPercent
        );

        if ($warningRemainingDays === null) {
            return false;
        }

        return $remainingBusinessDays <= $warningRemainingDays;
    }
}
