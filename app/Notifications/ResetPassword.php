<?php

namespace App\Notifications;

use App\Support\AccountManager\UserManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPassword extends Notification implements ShouldQueue
{
    use Queueable;

    public $organization;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(public UserManager $user, public string $code)
    {
        $this->organization = $this->user->organization();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return MailMessage
     */
    public function toMail($notifiable)
    {
        $user = $this->user;
        $organization = $this->organization;
        $code = $this->code;

        $name = $user->attribute('first_name').' '.$user->attribute('last_name');

        return (new MailMessage)
            ->subject($organization->name.' has sent a password reset on '.env('APP_NAME'))
            ->greeting(__('labels.greeting', ['name' => $name]))
            ->line(__('messages.notification.password.reset_link', ['organization', $organization->name]))
            ->line(__('messages.notification.account.username', ['username' => $user->attribute('username')]))
            ->action(__('messages.notification.password.reset_action'), url("/public/setpassword/$code"));
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toDatabase($notifiable)
    {
        $code = $this->code;

        return [
            'username' => $this->user->attribute('username'),
            'url' => url("/public/setpassword/$code"),
            'organization' => $this->organization->slug,
        ];
    }
}
