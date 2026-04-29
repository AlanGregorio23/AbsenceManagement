<?php

namespace App\Jobs\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AnnualHoursLimitReachedGuardianMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $guardianName,
        public string $studentName,
        public int $totalHours,
        public int $maxHours,
        public string $schoolYear
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Limite ore annuali raggiunto - '.$this->studentName
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.annual-hours-limit-reached-guardian',
            with: [
                'guardianName' => $this->guardianName,
                'studentName' => $this->studentName,
                'totalHours' => $this->totalHours,
                'maxHours' => $this->maxHours,
                'schoolYear' => $this->schoolYear,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
