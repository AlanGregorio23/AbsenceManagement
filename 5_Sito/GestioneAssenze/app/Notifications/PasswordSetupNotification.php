<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordSetupNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reimpostazione password')
            ->view('mail.auth.reset-password', [
                'resetUrl' => $this->resetUrl($notifiable),
                'email' => $notifiable->getEmailForPasswordReset(),
                'appName' => config('app.name'),
                'expiryMinutes' => null,
            ]);
    }

    public function token(): string
    {
        return $this->token;
    }

    protected function resetUrl(object $notifiable): string
    {
        return url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }
}
