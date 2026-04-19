<?php

namespace App\Support;

use App\Models\DelayRule;
use App\Models\User;
use App\Models\UserStudentStatusSetting;

class StudentStatusThresholdResolver
{
    public const STATUS_GREEN = 'green';

    public const STATUS_YELLOW = 'yellow';

    public const STATUS_RED = 'red';

    /**
     * @return array{
     *   max_annual_hours:int,
     *   warning_percent:int,
     *   critical_percent:int,
     *   absence_warning_percent:int,
     *   absence_critical_percent:int,
     *   delay_warning_percent:int,
     *   delay_critical_percent:int,
     *   warning_absence_hours:int,
     *   critical_absence_hours:int,
     *   delay_baseline_count:int,
     *   warning_registered_delays:int,
     *   critical_registered_delays:int
     * }
     */
    public function teacherThresholds(?User $viewer = null): array
    {
        $absenceSetting = SystemSettingsResolver::absenceSetting();
        $maxAnnualHours = max((int) $absenceSetting->max_annual_hours, 0);
        $settingsPayload = $this->resolveUserPercentSettings($viewer);
        $absenceWarningPercent = $settingsPayload['absence_warning_percent'];
        $absenceCriticalPercent = $settingsPayload['absence_critical_percent'];
        $delayWarningPercent = $settingsPayload['delay_warning_percent'];
        $delayCriticalPercent = $settingsPayload['delay_critical_percent'];

        $delayBaselineCount = $this->resolveDelayBaselineCount();

        return [
            'max_annual_hours' => $maxAnnualHours,
            'warning_percent' => $absenceWarningPercent,
            'critical_percent' => $absenceCriticalPercent,
            'absence_warning_percent' => $absenceWarningPercent,
            'absence_critical_percent' => $absenceCriticalPercent,
            'delay_warning_percent' => $delayWarningPercent,
            'delay_critical_percent' => $delayCriticalPercent,
            'warning_absence_hours' => $this->thresholdFromPercent($maxAnnualHours, $absenceWarningPercent),
            'critical_absence_hours' => $this->thresholdFromPercent($maxAnnualHours, $absenceCriticalPercent),
            'delay_baseline_count' => $delayBaselineCount,
            'warning_registered_delays' => $this->thresholdFromPercent($delayBaselineCount, $delayWarningPercent),
            'critical_registered_delays' => $this->thresholdFromPercent($delayBaselineCount, $delayCriticalPercent),
        ];
    }

    /**
     * @param  array{
     *   warning_absence_hours:int,
     *   critical_absence_hours:int,
     *   warning_registered_delays:int,
     *   critical_registered_delays:int
     * }  $thresholds
     * @return array{
     *   absence:string,
     *   delay:string
     * }
     */
    public function resolveTeacherSplitStatus(
        int $absenceHours,
        int $registeredDelays,
        array $thresholds
    ): array {
        return [
            'absence' => $this->resolveSeverityCode(
                $absenceHours,
                (int) ($thresholds['warning_absence_hours'] ?? 0),
                (int) ($thresholds['critical_absence_hours'] ?? 0)
            ),
            'delay' => $this->resolveSeverityCode(
                $registeredDelays,
                (int) ($thresholds['warning_registered_delays'] ?? 0),
                (int) ($thresholds['critical_registered_delays'] ?? 0)
            ),
        ];
    }

    /**
     * @param  array{
     *   warning_absence_hours:int,
     *   critical_absence_hours:int,
     *   warning_registered_delays:int,
     *   critical_registered_delays:int
     * }  $thresholds
     * @return array{label:string,badge:string}
     */
    public function resolveTeacherStatus(
        int $absenceHours,
        int $registeredDelays,
        array $thresholds
    ): array {
        $split = $this->resolveTeacherSplitStatus(
            $absenceHours,
            $registeredDelays,
            $thresholds
        );
        $dominantCode = self::STATUS_GREEN;
        if ($split['absence'] === self::STATUS_RED || $split['delay'] === self::STATUS_RED) {
            $dominantCode = self::STATUS_RED;
        } elseif ($split['absence'] === self::STATUS_YELLOW || $split['delay'] === self::STATUS_YELLOW) {
            $dominantCode = self::STATUS_YELLOW;
        }

        return [
            'label' => $this->severityLabel($dominantCode),
            'badge' => $this->severityBadge($dominantCode),
        ];
    }

    public function resolveSeverityCode(
        int $value,
        int $warningThreshold,
        int $criticalThreshold
    ): string {
        if ($criticalThreshold > 0 && $value >= $criticalThreshold) {
            return self::STATUS_RED;
        }

        if ($warningThreshold > 0 && $value >= $warningThreshold) {
            return self::STATUS_YELLOW;
        }

        return self::STATUS_GREEN;
    }

    public function severityBadge(string $code): string
    {
        return match ($code) {
            self::STATUS_RED => 'bg-rose-100 text-rose-700',
            self::STATUS_YELLOW => 'bg-amber-100 text-amber-700',
            default => 'bg-emerald-100 text-emerald-700',
        };
    }

    public function severityLabel(string $code): string
    {
        return match ($code) {
            self::STATUS_RED => 'Critico',
            self::STATUS_YELLOW => 'Monitorare',
            default => 'Regolare',
        };
    }

    private function sanitizePercent(int $value): int
    {
        return max(1, min(100, $value));
    }

    private function thresholdFromPercent(int $baseValue, int $percent): int
    {
        $safeBaseValue = max($baseValue, 0);
        if ($safeBaseValue <= 0) {
            return 0;
        }

        return max(
            1,
            (int) ceil(($safeBaseValue * max($percent, 0)) / 100)
        );
    }

    private function resolveDelayBaselineCount(): int
    {
        $delayRules = DelayRule::query()
            ->orderBy('min_delays')
            ->orderBy('max_delays')
            ->get(['min_delays', 'max_delays']);

        if ($delayRules->isEmpty()) {
            return 0;
        }

        $maxRangeLimit = (int) $delayRules
            ->pluck('max_delays')
            ->filter(fn ($value) => ! is_null($value))
            ->map(fn ($value) => (int) $value)
            ->max();

        if ($maxRangeLimit > 0) {
            return $maxRangeLimit;
        }

        $highestMin = (int) $delayRules
            ->pluck('min_delays')
            ->map(fn ($value) => (int) $value)
            ->max();

        return max(1, $highestMin);
    }

    /**
     * @return array{
     *   absence_warning_percent:int,
     *   absence_critical_percent:int,
     *   delay_warning_percent:int,
     *   delay_critical_percent:int
     * }
     */
    public function resolveUserPercentSettings(?User $viewer = null): array
    {
        $defaultWarning = UserStudentStatusSetting::DEFAULT_WARNING_PERCENT;
        $defaultCritical = UserStudentStatusSetting::DEFAULT_CRITICAL_PERCENT;

        if (
            ! $viewer
            || ! in_array((string) $viewer->role, ['teacher', 'laboratory_manager'], true)
        ) {
            return [
                'absence_warning_percent' => $defaultWarning,
                'absence_critical_percent' => $defaultCritical,
                'delay_warning_percent' => $defaultWarning,
                'delay_critical_percent' => $defaultCritical,
            ];
        }

        $settings = UserStudentStatusSetting::query()
            ->where('user_id', $viewer->id)
            ->first();

        if (! $settings) {
            return [
                'absence_warning_percent' => $defaultWarning,
                'absence_critical_percent' => $defaultCritical,
                'delay_warning_percent' => $defaultWarning,
                'delay_critical_percent' => $defaultCritical,
            ];
        }

        $absenceWarning = $this->sanitizePercent((int) $settings->absence_warning_percent);
        $absenceCritical = max(
            $absenceWarning,
            $this->sanitizePercent((int) $settings->absence_critical_percent)
        );
        $delayWarning = $this->sanitizePercent((int) $settings->delay_warning_percent);
        $delayCritical = max(
            $delayWarning,
            $this->sanitizePercent((int) $settings->delay_critical_percent)
        );

        return [
            'absence_warning_percent' => $absenceWarning,
            'absence_critical_percent' => $absenceCritical,
            'delay_warning_percent' => $delayWarning,
            'delay_critical_percent' => $delayCritical,
        ];
    }
}
