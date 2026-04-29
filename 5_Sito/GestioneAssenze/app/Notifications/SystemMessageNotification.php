<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SystemMessageNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $eventKey,
        private readonly array $content
    ) {}

    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['database'];
        }

        $channels = [];

        if (NotificationPreference::webEnabledFor($notifiable, $this->eventKey)) {
            $channels[] = 'database';
        }

        if (
            (bool) $notifiable->active
            && filled($notifiable->email)
            && NotificationPreference::emailEnabledFor($notifiable, $this->eventKey)
        ) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event_key' => $this->eventKey,
            'title' => (string) ($this->content['title'] ?? 'Nuova notifica'),
            'body' => (string) ($this->content['body'] ?? ''),
            'url' => $this->content['url'] ?? null,
            'icon' => (string) ($this->content['icon'] ?? 'system'),
            'action_label' => (string) ($this->content['action_label'] ?? 'Apri'),
            'action_type' => (string) ($this->content['action_type'] ?? 'open'),
            'reference_code' => $this->content['reference_code'] ?? null,
            'deadline_date' => $this->content['deadline_date'] ?? null,
            'warning_percent' => $this->content['warning_percent'] ?? null,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject((string) ($this->content['mail_subject'] ?? $this->content['title'] ?? 'Nuova notifica'))
            ->greeting('Ciao '.$this->resolveRecipientName($notifiable).',')
            ->line((string) ($this->content['title'] ?? 'Nuova notifica'));

        $body = trim((string) ($this->content['body'] ?? ''));
        if ($body !== '') {
            $mail->line($body);
        }

        $mailLine = trim((string) ($this->content['mail_line'] ?? ''));
        if ($mailLine !== '') {
            $mail->line($mailLine);
        }

        $url = trim((string) ($this->content['url'] ?? ''));
        if ($url !== '') {
            $actionLabel = trim((string) ($this->content['action_label'] ?? 'Apri notifica'));
            $mail->action($actionLabel !== '' ? $actionLabel : 'Apri notifica', $url);
        }

        return $mail->salutation('Gestione Assenze');
    }

    private function resolveRecipientName(object $notifiable): string
    {
        if ($notifiable instanceof User) {
            $name = trim($notifiable->fullName());

            return $name !== '' ? $name : 'utente';
        }

        return 'utente';
    }
}
