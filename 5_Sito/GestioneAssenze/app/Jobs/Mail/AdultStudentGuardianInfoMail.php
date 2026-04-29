<?php

namespace App\Jobs\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdultStudentGuardianInfoMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int,string>  $details
     */
    public function __construct(
        public string $subjectLine,
        public string $title,
        public string $intro,
        public array $details = [],
        public ?string $closing = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.adult-student-guardian-info',
            with: [
                'title' => $this->title,
                'intro' => $this->intro,
                'details' => $this->details,
                'closing' => $this->closing,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
