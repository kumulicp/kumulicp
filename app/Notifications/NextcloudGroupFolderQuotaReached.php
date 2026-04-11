<?php

namespace App\Notifications;

use App\AppInstance;
use App\Channels\PanelChannel;
use App\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NextcloudGroupFolderQuotaReached extends Notification implements ShouldQueue
{
    use Queueable;

    private $organization;

    private $folders;

    private $app_instance;

    private $message;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Organization $organization, AppInstance $app_instance, array $folders)
    {
        $this->organization = $organization;
        $this->app_instance = $app_instance;
        $this->folders = $folders;
        $this->message = $this->folders;
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
        if (count($this->folders) == 1) {
            $plural = 'folder is';
        } else {
            $plural = 'folders are';
        }

        return (new MailMessage)
            ->subject(__('messages.extensions.nextcloud.storage_quota_limited'))
            ->markdown('emails.notifications.nextcloud-storage-reached', ['organization' => $this->organization, 'folders' => $this->folders, 'app_instance' => $this->app_instance]);
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

    public function toPanel()
    {
        $folders = '';
        $folder_names = [];

        if (count($this->folders) == 1) {
            $folders = $this->folders[0]['name'].' team folder is at '.round($this->folders[0]['percent']).'%';
        }

        foreach ($this->folders as $folder) {
            $folder_names[] = $folder['name'];
        }

        return [
            'title' => __('messages.extensions.nextcloud.storage_quota_limited'),
            'notification_name' => 'nextcloud_group_folder_quota_reached',
            'folder_names' => $folder_names,
            'message' => $folders,
            'app_instance_name' => $this->app_instance->name,
        ];
    }
}
