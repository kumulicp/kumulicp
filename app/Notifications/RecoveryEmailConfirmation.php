<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to an address that was given as a recovery email, with the link that confirms it.
 */
class RecoveryEmailConfirmation extends Notification
{
    public function __construct(public string $username, public string $url) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('messages.notification.recovery.confirm_subject'))
            ->line(__('messages.notification.recovery.confirm_line', ['username' => $this->username, 'panel_name' => config('app.name')]))
            ->action(__('messages.notification.recovery.confirm_action'), $this->url)
            ->line(__('messages.notification.recovery.confirm_ignore', ['hours' => (int) config('email.recovery_confirm_hours', 24)]));
    }
}
