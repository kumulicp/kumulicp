<?php

namespace App\Notifications;

use App\Channels\PanelChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationDeactivated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct() {}

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
        $organization = $notifiable->organization();

        return (new MailMessage)
            ->subject(__('messages.notification.account_deactivated'))
            ->line(__('messages.notification.deactivation_notice'));
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
        return [
            'title' => __('messages.notification.account_deactivated'),
            'message' => __('messages.notification.deactivation_notice'),
        ];
    }
}
