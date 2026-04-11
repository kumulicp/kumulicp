<?php

namespace App\Notifications;

use App\Channels\PanelChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationUpgraded extends Notification implements ShouldQueue
{
    use Queueable;

    private $task;

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
        $version = $this->task->app_instance->version;
        if ($version->announcement_location == 'local') {
            $announcement_url = route('organization.announcements.show', ['id' => $version->announcement_id]);
        } else {
            $announcement_url = $version->announcement_url;
        }

        return (new MailMessage)
            ->subject(__('messages.notification.app_upgraded', ['app' => $this->task->application->name]))
            ->line(__('messages.notification.app_upgraded_notice', ['app' => $this->task->application->name]))
            ->action(__('messages.notification.read_announcement'), url($announcement_url))
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
            'message' => __('messages.notification.app_upgraded_notice', ['app' => $this->task->application->name]),
        ];
    }
}
