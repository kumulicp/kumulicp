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

    /**
     * Create a new notification instance.
     *
     * @param  array<int, array{type: string, app: string|null, from: string|null, to: string|null}>  $changes  The plan changes that were made, from SubscriptionSnapshot::planChanges()
     */
    public function __construct(public array $changes) {}

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
        $mail = (new MailMessage)->subject(__('messages.notification.subscription.updated_title'));

        foreach ($this->messages() as $message) {
            $mail->line($message);
        }

        return $mail;
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
            'title' => __('messages.notification.subscription.updated_title'),
            'message' => implode(' ', $this->messages()),
        ];
    }

    /**
     * One sentence for each plan that changed, saying what it changed from and to.
     *
     * @return array<int, string>
     */
    public function messages(): array
    {
        return array_map(function (array $change) {
            $replace = [
                'app' => $change['app'],
                'from' => $change['from'] ?? __('messages.notification.subscription.no_plan'),
                'to' => $change['to'] ?? __('messages.notification.subscription.no_plan'),
            ];

            return $change['type'] === 'base'
                ? __('messages.notification.subscription.base_plan_changed', $replace)
                : __('messages.notification.subscription.app_plan_changed', $replace);
        }, $this->changes);
    }
}
