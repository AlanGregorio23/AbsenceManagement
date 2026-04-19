<?php

namespace App\Mail;

use App\Models\Delay;
use App\Models\Guardian;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GuardianDelaySignatureMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public ?Delay $delayRecord,
        public ?Guardian $guardian,
        public string $signatureUrl,
        public ?CarbonInterface $expiresAt
    ) {}

    public function envelope(): Envelope
    {
        $studentName = trim((string) ($this->delayRecord?->student?->name ?? '').' '.(string) ($this->delayRecord?->student?->surname ?? ''));

        return new Envelope(
            subject: $studentName !== ''
                ? 'Conferma ritardo - '.$studentName
                : 'Conferma ritardo studente',
        );
    }

    public function content(): Content
    {
        $delayDateTime = $this->delayRecord?->delay_datetime?->format('d/m/Y H:i');
        $delayId = $this->delayRecord?->id;
        $delayMinutes = (int) ($this->delayRecord?->minutes ?? 0);
        $delayReason = (string) ($this->delayRecord?->notes ?? '-');

        return new Content(
            view: 'mail.guardian-delay-signature',
            with: [
                'guardian' => $this->guardian,
                'signatureUrl' => $this->signatureUrl,
                'expiresAt' => $this->expiresAt,
                'studentName' => trim((string) ($this->delayRecord?->student?->name ?? '').' '.(string) ($this->delayRecord?->student?->surname ?? '')),
                'delayId' => $delayId,
                'delayDateTime' => $delayDateTime,
                'delayMinutes' => $delayMinutes,
                'delayReason' => $delayReason,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
