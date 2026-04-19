<?php

namespace App\Observers;

use App\Models\Delay;
use App\Services\NotificationDispatcher;
use App\Services\NotificationRecipientResolver;
use Carbon\Carbon;

class DelayObserver
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationRecipientResolver $recipients
    ) {}

    public function created(Delay $delay): void
    {
        $delay->loadMissing('student');

        $studentName = $delay->student?->fullName() ?: 'Studente';
        $delayLabel = Carbon::parse($delay->delay_datetime)->format('d/m/Y H:i');

        $this->dispatcher->notifyUsers(
            $this->recipients->teachersForStudent((int) $delay->student_id),
            'teacher_new_delays',
            [
                'title' => 'Nuovo ritardo da verificare',
                'body' => $studentName.' ha segnalato un ritardo il '.$delayLabel.'.',
                'url' => route('teacher.delays.show', $delay),
                'icon' => 'delay',
                'mail_subject' => 'Nuovo ritardo da verificare',
            ]
        );
    }

    public function updated(Delay $delay): void
    {
        if (! $delay->wasChanged('status')) {
            return;
        }

        $currentStatus = Delay::normalizeStatus((string) $delay->status);
        $previousStatus = Delay::normalizeStatus((string) $delay->getOriginal('status'));

        if ($currentStatus === $previousStatus) {
            return;
        }

        $delay->loadMissing('student');

        if ($currentStatus === Delay::STATUS_JUSTIFIED) {
            $this->dispatcher->notifyUser(
                $delay->student,
                'student_delay_approved',
                [
                    'title' => 'Ritardo accettato',
                    'body' => 'Il tuo ritardo del '.Carbon::parse($delay->delay_datetime)->format('d/m/Y')
                        .' e stato giustificato.',
                    'url' => route('student.history'),
                    'icon' => 'delay',
                    'mail_subject' => 'Ritardo accettato',
                ]
            );

            return;
        }

        if ($currentStatus !== Delay::STATUS_REGISTERED) {
            return;
        }

        $this->dispatcher->notifyUser(
            $delay->student,
            'student_delay_registered',
            [
                'title' => 'Ritardo registrato',
                'body' => 'Il tuo ritardo del '.Carbon::parse($delay->delay_datetime)->format('d/m/Y')
                    .' e stato registrato nel conteggio.',
                'url' => route('student.history'),
                'icon' => 'delay',
                'mail_subject' => 'Ritardo registrato',
            ]
        );
    }
}
