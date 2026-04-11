<?php

namespace App\Notifications;

use App\Channels\PanelChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\View;

class NewAnnouncement extends Notification implements ShouldQueue
{
    use Queueable;

    public $announcement;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($announcement)
    {
        $this->announcement = $announcement;
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
        $body = $this->announcement->description;

        return (new MailMessage)
            ->subject($this->announcement->name)
            ->view('emails.notifications.html', ['body' => $body]);
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
            'message' => View::make('emails.notifications.html', ['body' => $this->announcement->description]),
        ];
    }
}
