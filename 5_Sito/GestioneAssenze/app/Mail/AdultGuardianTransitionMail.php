<?php

namespace App\Mail;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdultGuardianTransitionMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int,string>  $previousGuardianEmails
     */
    public function __construct(
        public User $student,
        public array $previousGuardianEmails,
        public CarbonInterface $effectiveDate
    ) {}

    public function envelope(): Envelope
    {
        $studentName = trim((string) ($this->student->name ?? '').' '.(string) ($this->student->surname ?? ''));

        return new Envelope(
            subject: $studentName !== ''
                ? 'Cambio tutore per maggiore eta - '.$studentName
                : 'Cambio tutore per maggiore eta',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.adult-guardian-transition',
            with: [
                'student' => $this->student,
                'studentName' => trim((string) ($this->student->name ?? '').' '.(string) ($this->student->surname ?? '')),
                'previousGuardianEmails' => $this->previousGuardianEmails,
                'effectiveDate' => $this->effectiveDate,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
