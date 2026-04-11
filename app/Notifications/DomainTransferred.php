<?php

namespace App\Notifications;

use App\Channels\PanelChannel;
use App\OrgDomain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DomainTransferred extends Notification implements ShouldQueue
{
    use Queueable;

    public $domain;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(OrgDomain $domain)
    {
        $this->domain = $domain;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return [PanelChannel::class, 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject(__('messages.notification.domain_transferred', ['domain' => $this->domain->name]))
            ->line(__('messages.notification.domain_transferred_notice', ['domain' => $this->domain->name, 'appname' => env('APP_NAME')]))
            ->action(__('messages.notification.manage_domains'), url(config('app.url').'/settings/domains'));
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
        ];
    }

    public function toPanel()
    {
        return [
            'message' => __('messages.notification.domain_transferred_notice', ['domain' => $this->domain->name, 'appname' => env('APP_NAME')]),
        ];
    }
}
