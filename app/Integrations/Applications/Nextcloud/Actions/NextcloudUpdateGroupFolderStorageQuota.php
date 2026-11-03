<?php

namespace App\Integrations\Applications\Nextcloud\Actions;

use App\Events\AppInstanceSubscriptionChanged;
use App\Integrations\Applications\Nextcloud\API\GroupFolders;
use App\Services\AdditionalStorageService;
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
        $app_instance = $event->app_instance;

        if ($hub = $app_instance->sharedPlanParent()) {
            // updateAllQuotas() below recomputes every folder in the
            // instance using whichever app_instance/organization triggered
            // the event -- correct for standalone (one org owns every
            // folder in its own instance) but wrong here: it would blast
            // every other org's folder with this org's plan quota. Shared
            // children only ever get their own folder updated.
            $organization = $app_instance->organization;
            $group_folder = new GroupFolders($hub);
            $group_folder->findByName($organization->name);

            if ($group_folder->exists()) {
                $additional_storage = new AdditionalStorageService($organization, 'group', $organization->name, $app_instance);
                $group_folder->updateQuota($additional_storage);
            }

            return;
        }

        $group_folder = new GroupFolders($app_instance);
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
