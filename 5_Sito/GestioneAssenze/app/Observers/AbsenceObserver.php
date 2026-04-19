<?php

namespace App\Observers;

use App\Models\Absence;
use App\Services\AbsenceGuardianSignatureService;
use App\Services\AnnualHoursLimitAlertService;
use App\Services\NotificationDispatcher;
use App\Services\NotificationRecipientResolver;
use Carbon\Carbon;

class AbsenceObserver
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationRecipientResolver $recipients,
        private readonly AnnualHoursLimitAlertService $annualHoursAlert,
        private readonly AbsenceGuardianSignatureService $guardianSignatureService
    ) {}

    public function created(Absence $absence): void
    {
        $absence->loadMissing('student');

        $studentName = $absence->student?->fullName() ?: 'Studente';
        $periodLabel = $this->formatPeriod($absence->start_date, $absence->end_date);

        $this->dispatcher->notifyUsers(
            $this->recipients->teachersForStudent((int) $absence->student_id),
            'teacher_new_absences',
            [
                'title' => 'Nuova assenza da verificare',
                'body' => $studentName.' ha inviato un assenza per '.$periodLabel.'.',
                'url' => route('teacher.absences.show', $absence),
                'icon' => 'absence',
                'mail_subject' => 'Nuova assenza da verificare',
            ]
        );

        $this->annualHoursAlert->notifyIfReached((int) $absence->student_id);
    }

    public function updated(Absence $absence): void
    {
        $this->annualHoursAlert->notifyIfReached((int) $absence->student_id);

        if (! $absence->wasChanged('status')) {
            return;
        }

        $currentStatus = Absence::normalizeStatus((string) $absence->status);
        $previousStatus = Absence::normalizeStatus((string) $absence->getOriginal('status'));

        if ($currentStatus === $previousStatus) {
            return;
        }

        $absence->loadMissing('student');
        $periodLabel = $this->formatPeriod($absence->start_date, $absence->end_date);

        if ($currentStatus === Absence::STATUS_JUSTIFIED) {
            $this->dispatcher->notifyUser(
                $absence->student,
                'student_absence_approved',
                [
                    'title' => 'Assenza accettata',
                    'body' => 'La tua assenza per '.$periodLabel.' e stata giustificata.',
                    'url' => route('student.history'),
                    'icon' => 'absence',
                    'mail_subject' => 'Assenza accettata',
                ]
            );

            return;
        }

        if ($currentStatus !== Absence::STATUS_ARBITRARY) {
            return;
        }

        $this->dispatcher->notifyUser(
            $absence->student,
            'student_absence_arbitrary',
            [
                'title' => 'Assenza arbitraria',
                'body' => 'La tua assenza per '.$periodLabel.' e stata segnata come arbitraria.',
                'url' => route('student.history'),
                'icon' => 'absence',
                'mail_subject' => 'Assenza arbitraria',
            ]
        );

        $this->dispatcher->notifyUsers(
            $this->recipients->teachersForStudent((int) $absence->student_id),
            'teacher_absence_arbitrary',
            [
                'title' => 'Assenza diventata arbitraria',
                'body' => ($absence->student?->fullName() ?: 'Studente')
                    .' ha un assenza diventata arbitraria per '.$periodLabel.'.',
                'url' => route('teacher.absences.show', $absence),
                'icon' => 'absence',
                'mail_subject' => 'Assenza diventata arbitraria',
            ]
        );

        $this->guardianSignatureService->sendInformativeEmails($absence);
    }

    private function formatPeriod(mixed $startDate, mixed $endDate): string
    {
        $from = Carbon::parse($startDate)->format('d/m/Y');
        $to = Carbon::parse($endDate ?? $startDate)->format('d/m/Y');

        return $from === $to ? $from : $from.' - '.$to;
    }
}
