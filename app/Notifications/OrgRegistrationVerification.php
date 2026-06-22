<?php

namespace App\Notifications;

use App\OrgUserRegistration;
use App\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrgRegistrationVerification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Organization $organization,
        public OrgUserRegistration $registration
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = url("/public/org/{$this->organization->slug}/register/verify/{$this->registration->token}");

        return (new MailMessage)
            ->subject(__('messages.notification.org_registration.subject', ['org' => $this->organization->name]))
            ->greeting(__('messages.notification.org_registration.greeting'))
            ->line(__('messages.notification.org_registration.line1', ['org' => $this->organization->name]))
            ->action(__('messages.notification.org_registration.action'), $url)
            ->line(__('messages.notification.org_registration.line2'))
            ->line(__('messages.notification.org_registration.expiry'));
    }
}
