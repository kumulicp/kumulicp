<?php

namespace App\Notifications;

use App\Support\AccountManager\UserManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserCreated extends Notification implements ShouldQueue
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

        $user_apps = $user->allUserApps();
        $app_names = [];
        foreach ($user_apps as $app) {
            $app_names[] = $app->label;
        }
        $app_list = implode(', ', $app_names);

        $name = $user->attribute('first_name').' '.$user->attribute('last_name');

        $mail_message = (new MailMessage)
            ->subject(__('messages.notification.account.created', ['name' => $organization->name, 'panel_name' => env('APP_NAME')]))
            ->greeting(__('messages.notification.welcome', ['name' => $name]));
        if (count($app_names) > 0) {
            $mail_message->line(__('messages.notification.account.app_access', ['panel_name' => env('APP_NAME'), 'app_list' => $app_list]));
        }
        $mail_message->line(__('messages.notification.account.username', ['username' => $user->attribute('username')]))
            ->action(__('messages.notification.password.set'), url("/public/setpassword/$code"));

        return $mail_message;
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
