<?php

namespace App\Notifications;

use App\AppInstance;
use App\Channels\PanelChannel;
use App\Services\AppInstance\AvailabilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Shared by the two notifications about an app's API not answering. Both go
 * to the panel and by email; $details says whether the recipient also sees
 * the technical reason (system admins) or not (an organization's admins).
 */
abstract class AppInstanceAvailabilityNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public AppInstance $app_instance,
        public bool $details = false,
    ) {}

    /** Language key under messages.notification for this notification */
    abstract protected function key(): string;

    public function via($notifiable)
    {
        return [PanelChannel::class, 'mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject($this->title())
            ->markdown('emails.notifications.app-instance-availability', $this->viewData());
    }

    public function toArray($notifiable)
    {
        return [];
    }

    public function toPanel()
    {
        return [
            'title' => $this->title(),
            'notification_name' => $this->key(),
            'message' => $this->message(),
            'app_instance_name' => $this->app_instance->name,
        ];
    }

    protected function title(): string
    {
        return __('messages.notification.'.$this->key().'.title', $this->replacements());
    }

    protected function message(): string
    {
        return __('messages.notification.'.$this->key().'.message', $this->replacements());
    }

    private function replacements(): array
    {
        return [
            'app' => $this->app_instance->label,
            'minutes' => AvailabilityService::RECHECK_MINUTES,
        ];
    }

    private function viewData(): array
    {
        $availability = $this->app_instance->setting('availability') ?? [];

        return [
            'message' => $this->message(),
            'app_instance' => $this->app_instance,
            'organization' => $this->app_instance->organization,
            'since' => $availability['since'] ?? null,
            'reason' => $this->details ? ($availability['last_error'] ?? null) : null,
            'minutes' => AvailabilityService::RECHECK_MINUTES,
            'key' => $this->key(),
        ];
    }
}
