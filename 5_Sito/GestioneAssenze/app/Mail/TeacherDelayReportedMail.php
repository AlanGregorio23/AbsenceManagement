<?php

namespace App\Mail;

use App\Models\Delay;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeacherDelayReportedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Delay $delayRecord,
        public string $studentName,
        public int $reportedMinutes
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nuovi ritardi segnalati - '.$this->studentName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.teacher-delay-reported',
            with: [
                'delayRecord' => $this->delayRecord,
                'studentName' => $this->studentName,
                'reportedMinutes' => $this->reportedMinutes,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
