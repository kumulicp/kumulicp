<?php

namespace App\Notifications;

use App\Channels\PanelChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $task;

    public $plan;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($task)
    {
        $this->task = $task;
        $this->plan = $task->organization->plan;
    }

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
        if ($this->plan) {
            return (new MailMessage)
                ->line(__('messages.notification.subscription.updated', ['plan' => $this->plan->name]));
        } else {
            return (new MailMessage)
                ->line(__('messages.notification.subscription.update_failed', ['plan' => $this->plan->name]));
        }
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
            'title' => __('messages.notification.subscription.updated_title', ['plan' => $this->plan->name]),
            'message' => __('messages.notification.subscription.updated', ['plan' => $this->plan->name]),
        ];
    }
}
