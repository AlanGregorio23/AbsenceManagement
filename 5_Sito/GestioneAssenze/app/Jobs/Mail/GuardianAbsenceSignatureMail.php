<?php

namespace App\Jobs\Mail;

use App\Models\Absence;
use App\Models\Guardian;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GuardianAbsenceSignatureMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Absence $absence,
        public Guardian $guardian,
        public string $signatureUrl,
        public ?CarbonInterface $expiresAt
    ) {}

    public function envelope(): Envelope
    {
        $studentName = trim((string) ($this->absence->student?->name ?? '').' '.(string) ($this->absence->student?->surname ?? ''));

        return new Envelope(
            subject: $studentName !== ''
                ? 'Conferma assenza - '.$studentName
                : 'Conferma assenza studente',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.guardian-absence-signature',
            with: [
                'absence' => $this->absence,
                'guardian' => $this->guardian,
                'signatureUrl' => $this->signatureUrl,
                'expiresAt' => $this->expiresAt,
                'studentName' => trim((string) ($this->absence->student?->name ?? '').' '.(string) ($this->absence->student?->surname ?? '')),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
