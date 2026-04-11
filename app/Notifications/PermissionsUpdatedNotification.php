<?php

namespace App\Notifications;

use App\Support\AccountManager\UserManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PermissionsUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private $permissions;

    private $user;

    private $organization;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($permissions, UserManager $user)
    {
        $this->permissions = $permissions;
        $this->organization = $user->organization();
        $this->user = $user;
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
        $organization = $this->organization;

        $permissions = $this->permissions;
        $user = $this->user;

        return (new MailMessage)
            ->subject($organization->name.': '.__('messages.notification.permissions.updated'))
            ->greeting(__('labels.greeting', ['name' => $user->attribute('name')]))
            ->markdown('emails.user.permissions-updated', ['permissions' => $permissions, 'user' => $user, 'apps' => $user->apps()]);
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
}
