<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Sent to the recovery email an account had, when it was replaced.
 */
class RecoveryEmailChanged extends Notification
{
    public function __construct(public string $username, public string $new, public ?Carbon $until) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('messages.notification.recovery.changed_subject'))
            ->line(__('messages.notification.recovery.changed_line', ['username' => $this->username, 'panel_name' => config('app.name'), 'new' => $this->new]));

        if ($this->until) {
            $message->line(__('messages.notification.recovery.changed_delay', ['date' => $this->until->toDayDateTimeString()]));
        }

        return $message;
    }
}
