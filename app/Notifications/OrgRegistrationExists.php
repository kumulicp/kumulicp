<?php

namespace App\Notifications;

use App\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent in place of the verification link when the address someone registers with already has an
 * account. The registration page answers the same for every address, so this is how the owner of
 * the address finds out, and it is where they go to sign in or reset their password.
 */
class OrgRegistrationExists extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Organization $organization) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('messages.notification.org_registration.exists_subject', ['org' => $this->organization->name]))
            ->greeting(__('messages.notification.org_registration.greeting'))
            ->line(__('messages.notification.org_registration.exists_line1', ['org' => $this->organization->name]))
            ->action(__('messages.notification.org_registration.exists_action'), route('login'))
            ->line(__('messages.notification.org_registration.exists_reset', ['url' => route('password.request')]))
            ->line(__('messages.notification.org_registration.line2'));
    }
}
