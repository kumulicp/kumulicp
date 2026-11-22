<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to an address outside the organization that someone wants mail forwarded to. Nothing is
 * forwarded until the link in it is opened.
 */
class ForwarderDestinationConfirmation extends Notification
{
    public function __construct(public string $forwarder_address, public string $url) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('messages.notification.forwarder.subject', ['address' => $this->forwarder_address]))
            ->line(__('messages.notification.forwarder.line', ['address' => $this->forwarder_address, 'panel_name' => config('app.name')]))
            ->action(__('messages.notification.forwarder.action'), $this->url)
            ->line(__('messages.notification.forwarder.ignore', ['hours' => (int) config('email.forwarder_confirm_hours', 72)]));
    }
}
