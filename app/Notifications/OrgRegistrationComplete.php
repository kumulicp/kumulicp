<?php

namespace App\Notifications;

use App\Organization;
use App\Support\AccountManager\UserManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrgRegistrationComplete extends Notification implements ShouldQueue
{
    use Queueable;

    public Organization $organization;

    public function __construct(public UserManager $user, public string $code)
    {
        $this->organization = $this->user->organization();
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $user = $this->user;
        $organization = $this->organization;
        $code = $this->code;

        $app_names = collect($user->allUserApps())->map(fn ($app) => $app->label)->implode(', ');

        $name = $user->attribute('first_name').' '.$user->attribute('last_name');

        $mail_message = (new MailMessage)
            ->subject(__('messages.notification.org_registration.welcome_subject', ['org' => $organization->name]))
            ->greeting(__('messages.notification.org_registration.welcome_greeting', ['name' => $name]))
            ->line(__('messages.notification.org_registration.welcome_line1', ['org' => $organization->name]));

        if ($app_names !== '') {
            $mail_message->line(__('messages.notification.org_registration.welcome_app_access', ['app_list' => $app_names]));
        }

        $mail_message->line(__('messages.notification.org_registration.welcome_username', ['username' => $user->attribute('username')]))
            ->action(__('messages.notification.password.set'), url("/public/setpassword/$code"));

        return $mail_message;
    }

    public function toDatabase($notifiable): array
    {
        return [
            'username' => $this->user->attribute('username'),
            'url' => url("/public/setpassword/{$this->code}"),
            'organization' => $this->organization->slug,
        ];
    }
}
