<?php

namespace App\Notifications;

use App\AppInstance;
use App\Channels\PanelChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationDeactivated extends Notification implements ShouldQueue
{
    use Queueable;

    private $app;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(AppInstance $app)
    {
        $this->app = $app;
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
        return (new MailMessage)
            ->subject(__('messages.notification.app_deactivated', ['app' => $this->app->label]))
            ->line(__('messages.notification.app_deactivation_notice', ['app' => $this->app->label]));
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

    public function toPanel()
    {
        return [
            'title' => __('messages.notification.app_deactivated', ['app' => $this->app->label]),
            'message' => __('messages.notification.app_deactivation_notice', ['app' => $this->app->label]),
        ];
    }
}
