<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\AbsenceEmailNotification;
use App\Models\Delay;
use App\Models\DelayEmailNotification;
use App\Models\User;
use App\Notifications\SystemMessageNotification;
use Carbon\Carbon;
use Throwable;

class StudentDeadlineReminderService
{
    public function sendAbsenceReminder(Absence $absence, Carbon $deadline, int $warningPercent): void
    {
        $absence->loadMissing('student');
        $student = $absence->student;
        if (! $student) {
            return;
        }

        $referenceCode = 'A-'.str_pad((string) $absence->id, 4, '0', STR_PAD_LEFT);
        $deadlineDate = $deadline->copy()->startOfDay();
        $type = 'student_deadline_warning_'.$warningPercent.'_'.$deadlineDate->toDateString();
        $recipientKey = $this->resolveRecipientKey($student);

        if ($this->absenceReminderAlreadySent($absence->id, $type, $recipientKey)) {
            return;
        }

        $title = 'Scadenza assenza in avvicinamento';
        $body = 'La pratica '.$referenceCode.' deve essere completata entro il '
            .$deadlineDate->format('d/m/Y').'. Controllala prima della scadenza.';
        $notification = new SystemMessageNotification('student_absence_deadline_warning', [
            'title' => $title,
            'body' => $body,
            'url' => route('student.history', ['open' => $referenceCode]),
            'icon' => 'absence',
            'action_label' => 'Apri pratica',
            'mail_subject' => $title,
            'mail_line' => 'Riferimento pratica: '.$referenceCode.'.',
            'reference_code' => $referenceCode,
            'deadline_date' => $deadlineDate->toDateString(),
            'warning_percent' => $warningPercent,
        ]);

        $channels = $notification->via($student);
        if ($channels === []) {
            return;
        }

        try {
            $student->notify($notification);

            AbsenceEmailNotification::query()->create([
                'type' => $type,
                'recipient_email' => $recipientKey,
                'subject' => $title,
                'body' => $body,
                'absence_id' => $absence->id,
                'sent_at' => now(),
                'status' => 'sent',
            ]);
        } catch (Throwable $exception) {
            AbsenceEmailNotification::query()->create([
                'type' => $type,
                'recipient_email' => $recipientKey,
                'subject' => $title,
                'body' => $body,
                'absence_id' => $absence->id,
                'status' => 'failed',
            ]);

            report($exception);
        }
    }

    public function sendDelayReminder(Delay $delay, Carbon $deadline, int $warningPercent): void
    {
        $delay->loadMissing('student');
        $student = $delay->student;
        if (! $student) {
            return;
        }

        $referenceCode = 'R-'.str_pad((string) $delay->id, 4, '0', STR_PAD_LEFT);
        $deadlineDate = $deadline->copy()->startOfDay();
        $type = 'student_deadline_warning_'.$warningPercent.'_'.$deadlineDate->toDateString();
        $recipientKey = $this->resolveRecipientKey($student);

        if ($this->delayReminderAlreadySent($delay->id, $type, $recipientKey)) {
            return;
        }

        $title = 'Scadenza ritardo in avvicinamento';
        $body = 'La pratica '.$referenceCode.' deve essere completata entro il '
            .$deadlineDate->format('d/m/Y').'. Controllala prima della scadenza.';
        $notification = new SystemMessageNotification('student_delay_deadline_warning', [
            'title' => $title,
            'body' => $body,
            'url' => route('student.history', ['open' => $referenceCode]),
            'icon' => 'delay',
            'action_label' => 'Apri pratica',
            'mail_subject' => $title,
            'mail_line' => 'Riferimento pratica: '.$referenceCode.'.',
            'reference_code' => $referenceCode,
            'deadline_date' => $deadlineDate->toDateString(),
            'warning_percent' => $warningPercent,
        ]);

        $channels = $notification->via($student);
        if ($channels === []) {
            return;
        }

        try {
            $student->notify($notification);

            DelayEmailNotification::query()->create([
                'type' => $type,
                'recipient_email' => $recipientKey,
                'subject' => $title,
                'body' => $body,
                'delay_id' => $delay->id,
                'sent_at' => now(),
                'status' => 'sent',
            ]);
        } catch (Throwable $exception) {
            DelayEmailNotification::query()->create([
                'type' => $type,
                'recipient_email' => $recipientKey,
                'subject' => $title,
                'body' => $body,
                'delay_id' => $delay->id,
                'status' => 'failed',
            ]);

            report($exception);
        }
    }

    private function absenceReminderAlreadySent(
        int $absenceId,
        string $type,
        string $recipientKey
    ): bool {
        return AbsenceEmailNotification::query()
            ->where('absence_id', $absenceId)
            ->where('type', $type)
            ->where('recipient_email', $recipientKey)
            ->where('status', 'sent')
            ->exists();
    }

    private function delayReminderAlreadySent(
        int $delayId,
        string $type,
        string $recipientKey
    ): bool {
        return DelayEmailNotification::query()
            ->where('delay_id', $delayId)
            ->where('type', $type)
            ->where('recipient_email', $recipientKey)
            ->where('status', 'sent')
            ->exists();
    }

    private function resolveRecipientKey(User $student): string
    {
        $email = strtolower(trim((string) $student->email));

        return $email !== '' ? $email : 'student:'.$student->id;
    }
}
