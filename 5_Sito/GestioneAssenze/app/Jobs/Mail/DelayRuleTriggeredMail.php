<?php

namespace App\Jobs\Mail;

use App\Models\Delay;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DelayRuleTriggeredMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int,string>  $actionLines
     */
    public function __construct(
        public Delay $delayRecord,
        public string $studentName,
        public int $delayCount,
        public array $actionLines
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Regole ritardi applicate - '.$this->studentName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.delay-rule-triggered',
            with: [
                'delayRecord' => $this->delayRecord,
                'studentName' => $this->studentName,
                'delayCount' => $this->delayCount,
                'actionLines' => $this->actionLines,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
