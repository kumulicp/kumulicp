<?php

namespace App\Notifications;

use App\Channels\PanelChannel;
use App\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationCancelledSubscription extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(private Organization $organization) {}

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return [PanelChannel::class, 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject(__('messages.notification.organization.unsubscribed', ['organization' => $organization->name]))
            ->line(__('messages.notification.organization.unsubscribed', ['organization' => $organization->name]))
            ->action('View '.$this->organization->name, url('/admin/organizations/'.$this->organization->id));
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }

    public function toPanel($notifiable)
    {
        $organization = $notifiable->organization();

        return [
            'title' => __('messages.notification.organization.unsubscribed', ['organization' => $organization->name]),
            'message' => __('messages.notification.organization.unsubscribed', ['organization' => $organization->name]),
        ];
    }
}
