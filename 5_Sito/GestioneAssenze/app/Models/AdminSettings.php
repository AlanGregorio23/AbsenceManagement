<?php

namespace App\Models;

use App\Support\SystemSettingsResolver;

class AdminSettings
{
    public static function forEdit(): array
    {
        $absenceSetting = SystemSettingsResolver::absenceSetting();
        $absenceReasons = AbsenceReason::orderBy('id')->get();
        $delaySetting = SystemSettingsResolver::delaySetting();
        $delayRules = DelayRule::orderBy('min_delays')->orderBy('max_delays')->get();
        $logSetting = SystemSettingsResolver::operationLogSetting();
        $loginSecuritySetting = SystemSettingsResolver::loginSecuritySetting();
        $schoolHolidays = SchoolHoliday::query()
            ->orderBy('holiday_date')
            ->orderBy('id')
            ->get();

        return [
            'absence' => [
                'max_annual_hours' => $absenceSetting->max_annual_hours,
                'warning_threshold_hours' => $absenceSetting->warning_threshold_hours,
                'vice_director_email' => $absenceSetting->vice_director_email,
                'guardian_signature_required' => $absenceSetting->guardian_signature_required,
                'medical_certificate_days' => $absenceSetting->medical_certificate_days,
                'medical_certificate_max_days' => $absenceSetting->medical_certificate_max_days,
                'absence_countdown_days' => $absenceSetting->absence_countdown_days,
                'pre_expiry_warning_percent' => (int) ($absenceSetting->pre_expiry_warning_percent ?? 80),
                'leave_request_notice_working_hours' => (int) ($absenceSetting->leave_request_notice_working_hours ?? 24),
            ],
            'reasons' => $absenceReasons->map(fn (AbsenceReason $reason) => [
                'id' => $reason->id,
                'name' => $reason->name,
                'counts_40_hours' => $reason->counts_40_hours,
                'requires_management_consent' => (bool) $reason->requires_management_consent,
                'requires_document_on_leave_creation' => (bool) $reason->requires_document_on_leave_creation,
                'management_consent_note' => $reason->management_consent_note,
            ])->values(),
            'delay' => [
                'minutes_threshold' => $delaySetting->minutes_threshold,
                'guardian_signature_required' => $delaySetting->guardian_signature_required,
                'deadline_active' => (bool) $delaySetting->deadline_active,
                'deadline_business_days' => (int) $delaySetting->deadline_business_days,
                'justification_business_days' => $delaySetting->justification_business_days,
                'pre_expiry_warning_business_days' => $delaySetting->pre_expiry_warning_business_days,
                'pre_expiry_warning_percent' => (int) ($delaySetting->pre_expiry_warning_percent ?? 80),
                'first_semester_end_day' => $delaySetting->resolvedFirstSemesterEndDay(),
                'first_semester_end_month' => $delaySetting->resolvedFirstSemesterEndMonth(),
            ],
            'delay_rules' => $delayRules->map(fn (DelayRule $rule) => [
                'id' => $rule->id,
                'min_delays' => $rule->min_delays,
                'max_delays' => $rule->max_delays,
                'actions' => $rule->actions ?? [],
                'info_message' => $rule->info_message,
            ])->values(),
            'logs' => [
                'interaction_retention_days' => $logSetting->interaction_retention_days,
                'error_retention_days' => $logSetting->error_retention_days,
            ],
            'login' => [
                'max_attempts' => $loginSecuritySetting->max_attempts,
                'decay_seconds' => $loginSecuritySetting->decay_seconds,
                'forgot_password_max_attempts' => $loginSecuritySetting->forgot_password_max_attempts,
                'forgot_password_decay_seconds' => $loginSecuritySetting->forgot_password_decay_seconds,
                'reset_password_max_attempts' => $loginSecuritySetting->reset_password_max_attempts,
                'reset_password_decay_seconds' => $loginSecuritySetting->reset_password_decay_seconds,
            ],
            'holidays' => $schoolHolidays->map(fn (SchoolHoliday $holiday) => [
                'id' => $holiday->id,
                'holiday_date' => $holiday->holiday_date?->toDateString(),
                'school_year' => $holiday->school_year,
                'label' => $holiday->label,
                'source' => $holiday->source,
                'source_file_path' => $holiday->source_file_path,
            ])->values(),
        ];
    }
}
