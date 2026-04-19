<?php

namespace App\Observers;

use App\Models\Leave;
use App\Services\AnnualHoursLimitAlertService;
use App\Services\NotificationDispatcher;
use App\Services\NotificationRecipientResolver;
use Carbon\Carbon;

class LeaveObserver
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationRecipientResolver $recipients,
        private readonly AnnualHoursLimitAlertService $annualHoursAlert
    ) {}

    public function created(Leave $leave): void
    {
        $leave->loadMissing('student');

        $studentName = $leave->student?->fullName() ?: 'Studente';
        $periodLabel = $this->formatPeriod($leave->start_date, $leave->end_date);

        $this->dispatcher->notifyUsers(
            $this->recipients->laboratoryManagers(),
            'lab_new_leaves',
            [
                'title' => 'Nuova richiesta di congedo',
                'body' => $studentName.' ha inviato un congedo per '.$periodLabel.'.',
                'url' => route('leaves.show', $leave),
                'icon' => 'leave',
                'mail_subject' => 'Nuova richiesta di congedo',
            ]
        );

        $this->annualHoursAlert->notifyIfReached((int) $leave->student_id);
    }

    public function updated(Leave $leave): void
    {
        $this->annualHoursAlert->notifyIfReached((int) $leave->student_id);

        if ($leave->wasChanged('documentation_path') && filled($leave->documentation_path)) {
            $leave->loadMissing('student');

            $studentName = $leave->student?->fullName() ?: 'Studente';

            $this->dispatcher->notifyUsers(
                $this->recipients->laboratoryManagers(),
                'lab_leave_documentation',
                [
                    'title' => 'Documentazione congedo caricata',
                    'body' => $studentName.' ha caricato nuova documentazione sul congedo '.$leave->id.'.',
                    'url' => route('leaves.show', $leave),
                    'icon' => 'leave',
                    'mail_subject' => 'Nuova documentazione congedo',
                ]
            );
        }

        if (! $leave->wasChanged('status')) {
            return;
        }

        $currentStatus = Leave::normalizeStatus((string) $leave->status);
        $previousStatus = Leave::normalizeStatus((string) $leave->getOriginal('status'));

        if ($currentStatus === $previousStatus) {
            return;
        }

        if (! in_array($currentStatus, [
            Leave::STATUS_DOCUMENTATION_REQUESTED,
            Leave::STATUS_APPROVED,
            Leave::STATUS_FORWARDED_TO_MANAGEMENT,
            Leave::STATUS_REJECTED,
        ], true)) {
            return;
        }

        $leave->loadMissing('student');
        $periodLabel = $this->formatPeriod($leave->start_date, $leave->end_date);

        if ($currentStatus === Leave::STATUS_DOCUMENTATION_REQUESTED) {
            $this->dispatcher->notifyUser(
                $leave->student,
                'student_leave_documentation_requested',
                [
                    'title' => 'Documentazione congedo richiesta',
                    'body' => 'Serve documentazione per il congedo del '.$periodLabel.'.',
                    'url' => route('student.documents'),
                    'icon' => 'leave',
                    'mail_subject' => 'Documentazione congedo richiesta',
                ]
            );

            return;
        }

        if ($currentStatus === Leave::STATUS_APPROVED) {
            $startDate = $leave->start_date
                ? Carbon::parse($leave->start_date)->startOfDay()
                : Carbon::today()->startOfDay();
            $draftAvailabilityMessage = $startDate->isFuture()
                ? 'La bozza assenza sara disponibile dal '.$startDate->format('d/m/Y').'.'
                : 'La bozza assenza e disponibile nella dashboard studente.';

            $this->dispatcher->notifyUser(
                $leave->student,
                'student_leave_approved',
                [
                    'title' => 'Congedo approvato',
                    'body' => 'Il tuo congedo per '.$periodLabel.' e stato approvato. '
                        .$draftAvailabilityMessage,
                    'url' => route('student.history'),
                    'icon' => 'leave',
                    'action_label' => 'Apri bozza',
                    'mail_subject' => 'Congedo approvato',
                ]
            );

            return;
        }

        if ($currentStatus === Leave::STATUS_FORWARDED_TO_MANAGEMENT) {
            $this->dispatcher->notifyUser(
                $leave->student,
                'student_leave_forwarded_to_management',
                [
                    'title' => 'Congedo inoltrato in direzione',
                    'body' => 'Il congedo per '.$periodLabel.' e stato inoltrato in direzione. Scarica il PDF di inoltro.',
                    'url' => route('leaves.forwarding-pdf.download', $leave),
                    'icon' => 'leave',
                    'action_label' => 'Scarica',
                    'action_type' => 'download',
                    'mail_subject' => 'Congedo inoltrato in direzione',
                ]
            );

            return;
        }

        $this->dispatcher->notifyUser(
            $leave->student,
            'student_leave_rejected',
            [
                'title' => 'Congedo rifiutato',
                'body' => 'Il tuo congedo per '.$periodLabel.' e stato rifiutato.',
                'url' => route('student.documents'),
                'icon' => 'leave',
                'mail_subject' => 'Congedo rifiutato',
            ]
        );
    }

    private function formatPeriod(mixed $startDate, mixed $endDate): string
    {
        $from = Carbon::parse($startDate)->format('d/m/Y');
        $to = Carbon::parse($endDate ?? $startDate)->format('d/m/Y');

        return $from === $to ? $from : $from.' - '.$to;
    }
}
