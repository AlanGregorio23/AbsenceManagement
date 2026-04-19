<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $passwordBroker = config('auth.defaults.passwords');
        $expiryMinutes = (int) config("auth.passwords.{$passwordBroker}.expire");

        return (new MailMessage)
            ->subject('Reimpostazione password')
            ->view('mail.auth.reset-password', [
                'resetUrl' => $this->resetUrl($notifiable),
                'email' => $notifiable->getEmailForPasswordReset(),
                'appName' => config('app.name'),
                'expiryMinutes' => $expiryMinutes,
            ]);
    }

    protected function resetUrl(object $notifiable): string
    {
        return url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }
}
