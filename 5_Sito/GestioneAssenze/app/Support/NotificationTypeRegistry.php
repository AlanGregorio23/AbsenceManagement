<?php

namespace App\Support;

class NotificationTypeRegistry
{
    private const ROLE_EVENT_DEFINITIONS = [
        'student' => [
            [
                'key' => 'student_absence_approved',
                'label' => 'Assenza accettata',
                'description' => 'Email quando una tua assenza viene giustificata.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'student_absence_arbitrary',
                'label' => 'Assenza arbitraria',
                'description' => 'Email quando una tua assenza viene segnata come arbitraria.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'student_absence_deadline_warning',
                'label' => 'Scadenza assenza in avvicinamento',
                'description' => 'Email quando una tua assenza si avvicina alla scadenza di completamento.',
                'default_email_enabled' => true,
            ],
            [
                'key' => 'student_delay_approved',
                'label' => 'Ritardo accettato',
                'description' => 'Email quando un tuo ritardo viene giustificato.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'student_delay_registered',
                'label' => 'Ritardo registrato',
                'description' => 'Email quando un tuo ritardo viene registrato nel conteggio.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'student_delay_deadline_warning',
                'label' => 'Scadenza ritardo in avvicinamento',
                'description' => 'Email quando un tuo ritardo si avvicina alla scadenza di completamento.',
                'default_email_enabled' => true,
            ],
            [
                'key' => 'student_leave_documentation_requested',
                'label' => 'Documentazione congedo richiesta',
                'description' => 'Email quando ti chiedono documentazione o allegati per un congedo.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'student_leave_approved',
                'label' => 'Congedo approvato',
                'description' => 'Email quando un tuo congedo viene approvato.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'student_leave_forwarded_to_management',
                'label' => 'Congedo inoltrato in direzione',
                'description' => 'Email quando il tuo congedo viene inoltrato in direzione e puoi scaricare il PDF.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'student_leave_rejected',
                'label' => 'Congedo rifiutato',
                'description' => 'Email quando un tuo congedo viene rifiutato.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'student_monthly_report_available',
                'label' => 'Report mensile disponibile',
                'description' => 'Email quando un nuovo report mensile e disponibile.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'student_monthly_report_approved',
                'label' => 'Report mensile approvato',
                'description' => 'Email quando il report mensile firmato viene approvato.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'student_monthly_report_rejected',
                'label' => 'Report mensile rifiutato',
                'description' => 'Email quando il report mensile firmato viene rifiutato e devi ricaricarlo.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'student_annual_hours_limit_reached',
                'label' => 'Limite ore annuali raggiunto',
                'description' => 'Email quando raggiungi il limite ore annuali.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'student_notify_inactive_guardians',
                'label' => 'Avvisa genitori (maggiorenne)',
                'description' => 'Se sei maggiorenne, invia email informative ai tutori precedenti (escluso te stesso) per assenze, ritardi e congedi.',
                'default_email_enabled' => false,
            ],
        ],
        'teacher' => [
            [
                'key' => 'teacher_new_absences',
                'label' => 'Nuove assenze',
                'description' => 'Nuove assenze inviate dagli studenti delle tue classi.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'teacher_new_delays',
                'label' => 'Nuovi ritardi',
                'description' => 'Nuovi ritardi segnalati dagli studenti delle tue classi.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'teacher_absence_arbitrary',
                'label' => 'Assenza diventata arbitraria',
                'description' => 'Email quando un assenza di classe viene segnata come arbitraria.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'teacher_absence_certificates',
                'label' => 'Certificati medici',
                'description' => 'Nuovi certificati caricati dagli studenti.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'teacher_monthly_report_signed_uploaded',
                'label' => 'Report mensile firmato caricato',
                'description' => 'Upload dei report mensili firmati dagli studenti.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'teacher_student_annual_hours_limit_reached',
                'label' => 'Studente al limite ore annuali',
                'description' => 'Email quando uno studente raggiunge il limite ore annuali.',
                'default_email_enabled' => false,
            ],
        ],
        'laboratory_manager' => [
            [
                'key' => 'lab_new_leaves',
                'label' => 'Nuovi congedi',
                'description' => 'Nuove richieste di congedo da prendere in carico.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'lab_leave_documentation',
                'label' => 'Documentazione congedi',
                'description' => 'Nuovi allegati caricati sugli iter di congedo.',
                'default_email_enabled' => false,
            ],
        ],
        'admin' => [
            [
                'key' => 'admin_system_warnings',
                'label' => 'Warning sistema',
                'description' => 'Email solo per avvisi tecnici e warning operativi.',
                'default_email_enabled' => false,
            ],
            [
                'key' => 'admin_system_errors',
                'label' => 'Errori sistema',
                'description' => 'Email solo per errori reali del sistema.',
                'default_email_enabled' => false,
            ],
        ],
    ];

    public static function forRole(?string $role): array
    {
        return self::ROLE_EVENT_DEFINITIONS[(string) $role] ?? [];
    }

    public static function eventKeysForRole(?string $role): array
    {
        return array_map(
            static fn (array $definition) => (string) $definition['key'],
            self::forRole($role)
        );
    }

    public static function supportsEmailForRole(?string $role, string $eventKey): bool
    {
        return in_array($eventKey, self::eventKeysForRole($role), true);
    }

    public static function defaultEmailEnabled(?string $role, string $eventKey): bool
    {
        foreach (self::forRole($role) as $definition) {
            if ((string) ($definition['key'] ?? '') !== $eventKey) {
                continue;
            }

            return (bool) ($definition['default_email_enabled'] ?? false);
        }

        return false;
    }
}
