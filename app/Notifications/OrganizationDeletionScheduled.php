<?php

namespace App\Notifications;

use App\Channels\PanelChannel;
use App\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationDeletionScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Organization $organization) {}

    public function via($notifiable)
    {
        return [PanelChannel::class, 'mail'];
    }

    public function toMail($notifiable)
    {
        $message = (new MailMessage)
            ->subject(__('messages.notification.organization_deletion_scheduled'))
            ->line(__('messages.notification.organization_deletion_notice', [
                'organization' => $this->organization->name,
                'date' => $this->organization->deletion_scheduled_at?->format('M d, Y'),
            ]));

        if ($reason = $this->organization->deactivationReasonText()) {
            $message->line(__('messages.notification.organization_deletion_reason', ['reason' => $reason]));
        }

        return $message;
    }

    public function toArray($notifiable)
    {
        return [
            //
        ];
    }

    public function toPanel($notifiable)
    {
        return [
            'title' => __('messages.notification.organization_deletion_scheduled'),
            'message' => __('messages.notification.organization_deletion_notice', [
                'organization' => $this->organization->name,
                'date' => $this->organization->deletion_scheduled_at?->format('M d, Y'),
            ]),
        ];
    }
}
