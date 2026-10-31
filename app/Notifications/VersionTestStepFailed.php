<?php

namespace App\Notifications;

use App\Channels\PanelChannel;
use App\VersionTestStep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VersionTestStepFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public VersionTestStep $step) {}

    /**
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return [PanelChannel::class, 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('messages.notification.version_test_step_failed', $this->replacements()))
            ->line(__('messages.notification.version_test_step_failed_notice', $this->replacements()))
            ->action(__('messages.notification.view_version_test'), url($this->url()));
    }

    /**
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [];
    }

    public function toPanel()
    {
        return [
            'title' => __('messages.notification.version_test_step_failed', $this->replacements()),
            'message' => __('messages.notification.version_test_step_failed_notice', $this->replacements()),
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        $run = $this->step->run;

        return '/admin/apps/'.$run->application->slug.'/versions/'.$run->version->name.'/tests/'.$run->id;
    }

    /**
     * @return array<string, string>
     */
    private function replacements(): array
    {
        $run = $this->step->run;

        return [
            'app' => $run->application->name,
            'version' => $run->version->name,
            'status' => $run->status->value,
            'plan' => $this->step->plan->name,
        ];
    }
}
