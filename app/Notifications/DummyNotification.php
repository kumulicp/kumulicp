<?php

namespace App\Notifications;

use App\Channels\PanelChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DummyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private $task;

    public $message;

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
        $database = config('auth.guards.web.provider') === 'users' ? 'database' : PanelChannel::class;

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
        if ($this->task->status == 'complete') {
            return (new MailMessage)
                ->subject($this->task->description.' '.__('labels.success'))
                ->line(__('messages.notification.dummy.completed'));
        } elseif ($this->task->status == 'failed') {
            return (new MailMessage)
                ->subject($this->task->description.' '.__('labels.failed'))
                ->line(__('messages.notification.dummy.failed'));
        }
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toDatabase($notifiable)
    {
        //
    }

    public function toPanel($notifiable)
    {
        return [
            'title' => __('labels.dummy_notification'),
            'message' => $this->task->status === 'complete' ? __('messages.notification.dummy.completed') : __('messages.notification.dummy.failed'),
        ];
    }
}
