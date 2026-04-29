<?php

namespace App\Services;

use App\Jobs\Mail\AdultStudentGuardianInfoMail;
use App\Models\OperationLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AdultGuardianPreferenceNotificationService
{
    public function __construct(
        private readonly InactiveGuardianNotificationResolver $inactiveGuardianNotificationResolver
    ) {}

    public function notifyToggle(
        User $student,
        bool $enabled,
        ?User $actor = null,
        ?Request $request = null
    ): void {
        $guardians = $this->inactiveGuardianNotificationResolver->resolveForPreferenceChange($student);
        if ($guardians->isEmpty()) {
            return;
        }

        $studentName = trim((string) ($student->name ?? '').' '.(string) ($student->surname ?? ''));
        $studentName = $studentName !== '' ? $studentName : 'Studente';
        $stateLabel = $enabled ? 'attivata' : 'disattivata';
        $subjectPrefix = $enabled
            ? 'Notifica informative ai tutori precedenti attivata'
            : 'Notifica informative ai tutori precedenti disattivata';
        $title = $enabled
            ? 'Avvisi ai tutori precedenti attivati'
            : 'Avvisi ai tutori precedenti disattivati';
        $intro = $enabled
            ? 'Lo studente maggiorenne ha attivato gli avvisi informativi per assenze, ritardi e congedi.'
            : 'Lo studente maggiorenne ha disattivato gli avvisi informativi per assenze, ritardi e congedi.';
        $closing = $enabled
            ? 'Da ora riceverai aggiornamenti informativi.'
            : 'Da ora non riceverai piu aggiornamenti informativi.';

        OperationLog::record(
            $actor ?? $student,
            $enabled
                ? 'student.previous_guardian_notifications.enabled'
                : 'student.previous_guardian_notifications.disabled',
            'student',
            $student->id,
            [
                'preference_key' => InactiveGuardianNotificationResolver::STUDENT_EVENT_KEY,
                'enabled' => $enabled,
                'recipients' => $guardians->pluck('email')->values()->all(),
            ],
            'INFO',
            $request
        );

        foreach ($guardians as $guardian) {
            $recipientEmail = strtolower(trim((string) $guardian->email));
            if ($recipientEmail === '') {
                continue;
            }

            try {
                Mail::to($recipientEmail)->send(new AdultStudentGuardianInfoMail(
                    $subjectPrefix.' - '.$studentName,
                    $title,
                    $intro,
                    [
                        'Studente: '.$studentName,
                        'Preferenza: '.$stateLabel,
                        'Data: '.now()->format('d/m/Y H:i'),
                    ],
                    $closing
                ));

                OperationLog::record(
                    $actor ?? $student,
                    'student.previous_guardian_notifications.email.sent',
                    'student',
                    $student->id,
                    [
                        'preference_key' => InactiveGuardianNotificationResolver::STUDENT_EVENT_KEY,
                        'enabled' => $enabled,
                        'guardian_id' => $guardian->id,
                        'guardian_email' => $recipientEmail,
                    ],
                    'INFO',
                    $request
                );
            } catch (Throwable $exception) {
                OperationLog::record(
                    $actor ?? $student,
                    'student.previous_guardian_notifications.email.failed',
                    'student',
                    $student->id,
                    [
                        'preference_key' => InactiveGuardianNotificationResolver::STUDENT_EVENT_KEY,
                        'enabled' => $enabled,
                        'guardian_id' => $guardian->id,
                        'guardian_email' => $recipientEmail,
                        'error' => $exception->getMessage(),
                    ],
                    'ERROR',
                    $request
                );

                report($exception);
            }
        }
    }
}
