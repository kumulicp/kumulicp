<?php

namespace App\Channels;

use App\User;
use Illuminate\Notifications\Notification;

class PanelChannel
{
    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     * @return void
     */
    public function send($notifiable, Notification $notification)
    {
        $username = is_a($notifiable, User::class) ? $notifiable->username : $notifiable->getFirstAttribute('cn');
        $type = get_class($notification);
        $message = $notification->toPanel($notifiable);

        $notification = new \App\Notification;
        $notification->type = $type;
        $notification->notifiable_type = 'user';
        $notification->notifiable_id = $username;
        $notification->data = $message;
        $notification->save();
    }
}
