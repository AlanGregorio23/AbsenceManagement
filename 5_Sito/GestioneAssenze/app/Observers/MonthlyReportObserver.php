<?php

namespace App\Observers;

use App\Models\MonthlyReport;
use App\Services\NotificationDispatcher;
use App\Services\NotificationRecipientResolver;

class MonthlyReportObserver
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationRecipientResolver $recipients
    ) {}

    public function updated(MonthlyReport $report): void
    {
        if (! $report->wasChanged('status')) {
            return;
        }

        $currentStatus = MonthlyReport::normalizeStatus((string) $report->status);
        $previousStatus = MonthlyReport::normalizeStatus((string) $report->getOriginal('status'));

        if ($currentStatus === $previousStatus) {
            return;
        }

        $report->loadMissing(['student', 'schoolClass']);

        if ($currentStatus === MonthlyReport::STATUS_SENT) {
            $this->dispatcher->notifyUser(
                $report->student,
                'student_monthly_report_available',
                [
                    'title' => 'Report mensile disponibile',
                    'body' => 'Il report mensile '.$report->monthLabel().' e disponibile nella tua area riservata.',
                    'url' => route('student.monthly-reports'),
                    'icon' => 'report',
                    'mail_subject' => 'Report mensile disponibile',
                ]
            );

            return;
        }

        if ($currentStatus === MonthlyReport::STATUS_SIGNED_UPLOADED) {
            $studentName = $report->student?->fullName() ?: 'Studente';
            $teacherRecipients = $report->class_id
                ? $this->recipients->teachersForClass((int) $report->class_id)
                : $this->recipients->teachersForStudent((int) $report->student_id);

            $this->dispatcher->notifyUsers(
                $teacherRecipients,
                'teacher_monthly_report_signed_uploaded',
                [
                    'title' => 'Report mensile firmato da verificare',
                    'body' => $studentName.' ha caricato il report mensile firmato '.$report->monthLabel().'.',
                    'url' => route('teacher.monthly-reports.show', $report),
                    'icon' => 'report',
                    'mail_subject' => 'Report mensile firmato da verificare',
                ]
            );

            return;
        }

        if ($currentStatus === MonthlyReport::STATUS_APPROVED) {
            $this->dispatcher->notifyUser(
                $report->student,
                'student_monthly_report_approved',
                [
                    'title' => 'Report mensile approvato',
                    'body' => 'Il report mensile '.$report->monthLabel().' e stato approvato.',
                    'url' => route('student.monthly-reports'),
                    'icon' => 'report',
                    'mail_subject' => 'Report mensile approvato',
                ]
            );

            return;
        }

        if ($currentStatus === MonthlyReport::STATUS_REJECTED) {
            $this->dispatcher->notifyUser(
                $report->student,
                'student_monthly_report_rejected',
                [
                    'title' => 'Report mensile rifiutato',
                    'body' => 'Il report mensile '.$report->monthLabel().' e stato rifiutato. Carica una nuova versione firmata.',
                    'url' => route('student.monthly-reports'),
                    'icon' => 'report',
                    'mail_subject' => 'Report mensile rifiutato',
                ]
            );
        }
    }
}
