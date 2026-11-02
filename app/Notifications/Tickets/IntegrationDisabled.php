<?php

namespace App\Notifications\Tickets;

use App\Channels\PanelChannel;
use App\TicketIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells staff that an integration kept failing and was switched off.
 */
class IntegrationDisabled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public TicketIntegration $integration) {}

    public function via(object $notifiable): array
    {
        return [PanelChannel::class, 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('tickets.integrations.disabled_title', ['name' => $this->integration->name]))
            ->line(__('tickets.integrations.disabled_line', ['name' => $this->integration->name]))
            ->line((string) $this->integration->disabled_reason)
            ->action(__('tickets.integrations.open'), url('/admin/support/integrations'));
    }

    /**
     * @return array{title: string, message: string, url: string}
     */
    public function toPanel(): array
    {
        return [
            'title' => __('tickets.integrations.disabled_title', ['name' => $this->integration->name]),
            'message' => (string) $this->integration->disabled_reason,
            'url' => '/admin/support/integrations',
        ];
    }
}
