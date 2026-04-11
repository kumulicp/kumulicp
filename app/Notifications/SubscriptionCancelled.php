<?php

namespace App\Notifications;

use App\Channels\PanelChannel;
use App\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionCancelled extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(public Organization $organization) {}

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
            ->subject(__('messages.notification.subscription_cancelled'))
            ->line(__('messages.notification.cancellation_notice', ['date' => $this->organization->deactivate_at?->format('M d, Y')]))
            ->action(__('messages.notification.review_plan'), url('/subscription/plans'));
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
            'title' => __('messages.notification.subscription_cancelled'),
            'message' => __('messages.notification.cancellation_notice_short', ['date' => $this->organization->deactivate_at->format('M d, Y')]),
        ];
    }
}
