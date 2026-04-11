<?php

namespace App\Integrations\Applications\Nextcloud\Services;

use App\AppInstance;
use App\Integrations\Applications\Nextcloud\API\GroupFolders;
use App\Services\AdditionalStorageService;
use App\Support\Facades\AccountManager;

class GroupFolderService
{
    public $group_folder;

    public $organization;

    public $group_name;

    public function __construct(AppInstance $app_instance, string $group_name)
    {
        $this->group_folder = new GroupFolders($app_instance);
        $this->group_folder->findByName($group_name);
        $this->organization = $app_instance->organization;
        $this->group_name = $group_name;

        $this->additional_storage = new AdditionalStorageService($app_instance->organization, 'group', $group_name, $app_instance);
    }

    public function add($name)
    {
        // If team folder doesn't exist, create new folder
        if (! $this->group_folder->exists()) {
            $this->group_folder->add($name, $this->additional_storage);

            // Add this group
            $this->group_folder->addGroup($name);
        }
    }

    public function update($name)
    {
        if (! $this->group_folder->exists()) {
            $this->add($name);
        } else {
            $this->updateName($name);
            // When changing group name, don't add/remove new group to the folder. The name will automatically be changed in Nextcloud
        }
    }

    public function updateManagers(array $managers)
    {
        // Get current managers
        $current_managers = [];

        if ($this->group_folder && $this->group_folder->data && $this->group_folder->data->manage->element) {
            foreach ($this->group_folder->data->manage->element as $current_manager) {
                $current_managers[] = (string) $current_manager->id;
            }
        }

        // Add new managers
        foreach ($managers as $manager) {
            $user = AccountManager::users()->find($manager);

            // If manager is a current_manager
            if ($user && $user->hasAccessToApps(['nextcloud']) && ! in_array($manager, $current_managers)) {
                $this->group_folder->addManager($manager);
            }
        }
        // Remove deleted managers
        foreach ($current_managers as $manager) {
            // If current_manager not a posted manager
            if (! in_array($manager, $managers)) {
                $this->group_folder->removeManager($manager);
            }
        }
    }

    // Update to new name
    public function updateName($name)
    {
        $this->group_folder->updateMountPoint($name);

        if ($this->additional_storage) {
            $this->additional_storage->updateName($name);
        }
    }

    public function updateQuota($quantity)
    {
        $this->group_folder->updateQuota($this->additional_storage);
    }

    public function delete()
    {
        if ($this->group_folder) {
            $this->group_folder->remove();
        }
        $this->additional_storage->delete();
    }
}
