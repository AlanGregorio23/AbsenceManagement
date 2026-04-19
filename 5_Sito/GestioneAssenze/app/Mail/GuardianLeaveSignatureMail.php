<?php

namespace App\Mail;

use App\Models\Guardian;
use App\Models\Leave;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GuardianLeaveSignatureMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Leave $leave,
        public Guardian $guardian,
        public string $signatureUrl,
        public ?CarbonInterface $expiresAt
    ) {}

    public function envelope(): Envelope
    {
        $studentName = trim((string) ($this->leave->student?->name ?? '').' '.(string) ($this->leave->student?->surname ?? ''));

        return new Envelope(
            subject: $studentName !== ''
                ? 'Conferma congedo - '.$studentName
                : 'Conferma congedo studente',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.guardian-leave-signature',
            with: [
                'leave' => $this->leave,
                'guardian' => $this->guardian,
                'signatureUrl' => $this->signatureUrl,
                'expiresAt' => $this->expiresAt,
                'studentName' => trim((string) ($this->leave->student?->name ?? '').' '.(string) ($this->leave->student?->surname ?? '')),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
