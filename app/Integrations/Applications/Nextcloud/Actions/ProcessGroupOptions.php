<?php

namespace App\Integrations\Applications\Nextcloud\Actions;

use App\Actions\Action;
use App\Actions\Organizations\SubscriptionUpdate;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\Integrations\Applications\Nextcloud\Jobs\RemoveGroupFolder;
use App\Integrations\Applications\Nextcloud\Jobs\UpdateGroupFolder;
use App\Integrations\Applications\Nextcloud\Services\GroupFolderService;
use App\Services\AdditionalStorageService;
use App\Support\AccountManager\GroupManager;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Subscription;
use Illuminate\Support\Arr;

class ProcessGroupOptions extends Action
{
    public $slug = 'process_group_options';

    public $action_group = 'nextcloud';

    public $status = 'in_progress';

    public $background = 1;

    // Used for action tasks that
    public function __construct(AppInstance $app_instance, GroupManager $group, $custom_values)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->group = $group;
        $this->setCustomValues($custom_values);
        $this->addCustomValue(['group_slug' => $group->attribute('slug')]);

        $this->description = __('actions.process_groups');

        $prereqs = new Prerequisites;
        $prereqs->add_application_required($app_instance);

        $name = array_key_exists('original_name', $custom_values) ? $custom_values['original_name'] : $custom_values['name'];

        if (Arr::get($custom_values, 'extensions.nextcloud_group_folder', false) == true) {
            // Update additional storage
            $additional_storage_service = new AdditionalStorageService($this->organization, 'group', $name, $this->app_instance);
            $additional_storage_service->updateQuantity(Arr::get($custom_values, 'extensions.nextcloud_additional_storage', 0));
            $this->additional_storage_service = $additional_storage_service;
        }

        $this->prerequisites = $prereqs->get();
    }

    public static function run($task)
    {
        if ($task->getValue('extensions.nextcloud_group_folder') == true) {
            UpdateGroupFolder::dispatch($task->app_instance, $task->custom_values, $task);
        } else {
            RemoveGroupFolder::dispatch($task->app_instance, $task->custom_values, $task);
        }

        ActionFacade::execute(new SubscriptionUpdate($task->organization->parent ?? $task->organization, Subscription::all()), background: true);
    }

    public static function retry($task)
    {
        $group = AccountManager::groups()->find($task->getValue('group_slug'));

        return new self($task->app_instance, $group, $task->customValues());
    }

    public static function complete($task)
    {
        $group = AccountManager::groups()->find($task->getValue('group_slug'));
        $group_name = $task->getValue('name');

        if ($group) {
            $group_service = new GroupFolderService($task->app_instance, $group_name);
            $group_exists = $group_service->group_folder->exists();
        } else {
            $group_exists = false;
        }

        if (($task->getValue('extensions.nextcloud_group_folder') == true && $group_exists)
            || ($task->getValue('extensions.nextcloud_group_folder') == false && ! $group_exists)) {
            $task->delete();
        }
    }
}
