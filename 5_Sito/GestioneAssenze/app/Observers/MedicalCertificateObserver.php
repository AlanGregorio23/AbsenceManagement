<?php

namespace App\Observers;

use App\Models\MedicalCertificate;
use App\Services\NotificationDispatcher;
use App\Services\NotificationRecipientResolver;
use Carbon\Carbon;

class MedicalCertificateObserver
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationRecipientResolver $recipients
    ) {}

    public function created(MedicalCertificate $certificate): void
    {
        $certificate->loadMissing('absence.student');

        $absence = $certificate->absence;
        if (! $absence) {
            return;
        }

        $studentName = $absence->student?->fullName() ?: 'Studente';
        $dayLabel = Carbon::parse($absence->start_date)->format('d/m/Y');

        $this->dispatcher->notifyUsers(
            $this->recipients->teachersForStudent((int) $absence->student_id),
            'teacher_absence_certificates',
            [
                'title' => 'Nuovo certificato medico',
                'body' => $studentName.' ha caricato un certificato per l assenza del '.$dayLabel.'.',
                'url' => route('teacher.absences.show', $absence),
                'icon' => 'certificate',
                'mail_subject' => 'Nuovo certificato medico da verificare',
            ]
        );
    }
}
