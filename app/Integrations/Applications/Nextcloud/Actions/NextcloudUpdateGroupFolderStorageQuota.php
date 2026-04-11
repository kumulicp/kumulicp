<?php

namespace App\Integrations\Applications\Nextcloud\Actions;

use App\Events\AppInstanceSubscriptionChanged;
use App\Integrations\Applications\Nextcloud\API\GroupFolders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class NextcloudUpdateGroupFolderStorageQuota implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct() {}

    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(AppInstanceSubscriptionChanged $event)
    {
        $group_folder = new GroupFolders($event->app_instance);
        $group_folder->updateAllQuotas();
    }

    /**
     * Determine whether the listener should be queued.
     *
     * @return bool
     */
    public function shouldQueue(AppInstanceSubscriptionChanged $event)
    {
        return $event->app_instance->application->slug == 'nextcloud';
    }
}
