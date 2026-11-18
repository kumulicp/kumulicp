<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Sent to the address an account used to sign in with, when an organization address
 * replaced it.
 */
class PrimaryEmailChanged extends Notification
{
    public function __construct(
        public string $username,
        public string $old,
        public string $new,
        public bool $now_recovery,
        public ?Carbon $undo_until,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('messages.notification.primary_email.subject'))
            ->line(__('messages.notification.primary_email.line', [
                'username' => $this->username,
                'panel_name' => config('app.name'),
                'old' => $this->old,
                'new' => $this->new,
            ]));

        if ($this->now_recovery) {
            $message->line(__('messages.notification.primary_email.recovery'));
        }

        if ($this->undo_until) {
            $message->line(__('messages.notification.primary_email.undo', ['date' => $this->undo_until->toDayDateTimeString()]));
        }

        return $message;
    }
}
