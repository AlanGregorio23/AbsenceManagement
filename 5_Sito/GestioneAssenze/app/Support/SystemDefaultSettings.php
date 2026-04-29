<?php

namespace App\Support;

use App\Models\DelaySetting;
use App\Models\LoginSecuritySetting;
use App\Models\OperationLogSetting;

class SystemDefaultSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function absenceSettingDefaults(): array
    {
        return [
            'max_annual_hours' => 40,
            'warning_threshold_hours' => 32,
            'student_status_warning_percent' => 80,
            'student_status_critical_percent' => 100,
            'vice_director_email' => null,
            'guardian_signature_required' => true,
            'medical_certificate_days' => 3,
            'medical_certificate_max_days' => 5,
            'absence_countdown_days' => 10,
            'pre_expiry_warning_percent' => 80,
            'leave_request_notice_working_hours' => 24,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function delaySettingDefaults(): array
    {
        return [
            'minutes_threshold' => 15,
            'guardian_signature_required' => true,
            'deadline_active' => false,
            'deadline_business_days' => 5,
            'justification_business_days' => 5,
            'pre_expiry_warning_business_days' => 1,
            'pre_expiry_warning_percent' => 80,
            'first_semester_end_day' => DelaySetting::DEFAULT_FIRST_SEMESTER_END_DAY,
            'first_semester_end_month' => DelaySetting::DEFAULT_FIRST_SEMESTER_END_MONTH,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function operationLogSettingDefaults(): array
    {
        return [
            'interaction_retention_days' => OperationLogSetting::DEFAULT_RETENTION_DAYS,
            'error_retention_days' => OperationLogSetting::DEFAULT_RETENTION_DAYS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function loginSecuritySettingDefaults(): array
    {
        return [
            'max_attempts' => LoginSecuritySetting::sanitizeMaxAttempts(
                (int) config('auth.login.max_attempts', 5)
            ),
            'decay_seconds' => LoginSecuritySetting::sanitizeDecaySeconds(
                (int) config('auth.login.decay_seconds', 300)
            ),
            'forgot_password_max_attempts' => LoginSecuritySetting::sanitizeForgotPasswordMaxAttempts(
                (int) config('auth.password_recovery.forgot.max_attempts', 6)
            ),
            'forgot_password_decay_seconds' => LoginSecuritySetting::sanitizeForgotPasswordDecaySeconds(
                (int) config('auth.password_recovery.forgot.decay_seconds', 60)
            ),
            'reset_password_max_attempts' => LoginSecuritySetting::sanitizeResetPasswordMaxAttempts(
                (int) config('auth.password_recovery.reset.max_attempts', 6)
            ),
            'reset_password_decay_seconds' => LoginSecuritySetting::sanitizeResetPasswordDecaySeconds(
                (int) config('auth.password_recovery.reset.decay_seconds', 60)
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function absenceReasonDefaults(): array
    {
        return [
            [
                'name' => 'Motivi familiari',
                'counts_40_hours' => true,
                'requires_management_consent' => false,
                'requires_document_on_leave_creation' => false,
                'management_consent_note' => null,
            ],
            [
                'name' => 'Visite mediche',
                'counts_40_hours' => true,
                'requires_management_consent' => false,
                'requires_document_on_leave_creation' => false,
                'management_consent_note' => null,
            ],
            [
                'name' => 'Medico generale',
                'counts_40_hours' => true,
                'requires_management_consent' => false,
                'requires_document_on_leave_creation' => false,
                'management_consent_note' => null,
            ],
            [
                'name' => 'Dentista generale',
                'counts_40_hours' => true,
                'requires_management_consent' => false,
                'requires_document_on_leave_creation' => false,
                'management_consent_note' => null,
            ],
            [
                'name' => 'Sciopero trasporti',
                'counts_40_hours' => false,
                'requires_management_consent' => false,
                'requires_document_on_leave_creation' => false,
                'management_consent_note' => null,
            ],
            [
                'name' => 'Maltempo',
                'counts_40_hours' => false,
                'requires_management_consent' => false,
                'requires_document_on_leave_creation' => false,
                'management_consent_note' => null,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function delayRuleDefaults(): array
    {
        return [
            [
                'min_delays' => 0,
                'max_delays' => 1,
                'actions' => [
                    ['type' => 'none'],
                ],
                'info_message' => null,
            ],
            [
                'min_delays' => 2,
                'max_delays' => 2,
                'actions' => [
                    ['type' => 'notify_student'],
                    ['type' => 'notify_guardian'],
                ],
                'info_message' => 'Al prossimo ritardo potrebbero essere previste segnalazioni',
            ],
            [
                'min_delays' => 3,
                'max_delays' => null,
                'actions' => [
                    ['type' => 'notify_teacher'],
                    ['type' => 'extra_activity_notice'],
                ],
                'info_message' => null,
            ],
            [
                'min_delays' => 4,
                'max_delays' => null,
                'actions' => [
                    ['type' => 'extra_activity_notice'],
                    ['type' => 'conduct_penalty', 'detail' => '-0.5 nota di condotta'],
                ],
                'info_message' => null,
            ],
            [
                'min_delays' => 5,
                'max_delays' => 6,
                'actions' => [
                    ['type' => 'extra_activity_notice'],
                ],
                'info_message' => null,
            ],
            [
                'min_delays' => 7,
                'max_delays' => 10,
                'actions' => [
                    ['type' => 'conduct_penalty', 'detail' => '-1.5 nota di condotta'],
                ],
                'info_message' => 'Nessuna ulteriore segnalazione di attivita extrascolastiche',
            ],
            [
                'min_delays' => 11,
                'max_delays' => null,
                'actions' => [
                    ['type' => 'conduct_penalty', 'detail' => 'nota di condotta 3'],
                ],
                'info_message' => null,
            ],
        ];
    }
}
