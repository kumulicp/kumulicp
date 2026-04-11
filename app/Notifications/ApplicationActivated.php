<?php

namespace App\Notifications;

use App\Channels\PanelChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationActivated extends Notification implements ShouldQueue
{
    use Queueable;

    private $task;

    private $message;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($task)
    {
        $this->task = $task;
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
            ->subject(__('messages.notification.app_activated', ['app' => $this->task->application->name]))
            ->line(__('messages.notification.app_activated_notice', ['app' => $this->task->application->name]))
            ->action(__('messages.notification.use_app', ['app' => $this->task->application->name]), url($this->task->app_instance->admin_address()))
            ->line(__('messages.notification.thank_you'));
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
            'title' => __('messages.notification.app_activated', ['app' => $this->task->application->name]),
            'message' => __('messages.notification.app_activated_notice', ['app' => $this->task->application->name]),
        ];
    }
}
