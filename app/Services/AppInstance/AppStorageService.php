<?php

namespace App\Services\AppInstance;

use App\AppInstance;
use App\Services\AdditionalStorageService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Subscription;

class AppStorageService
{
    private $additional_storage = [];

    public function __construct(private AppInstance $app_instance)
    {
        $this->organization = $this->app_instance->organization;
    }

    public function totalAppStorage()
    {
        $storage_quota = $this->app_instance->setting('storage_quota');

        if (! $storage_quota) {
            $storage_quota = $this->updateQuota();
        }

        return $storage_quota;
    }

    public function calculateTotalAppStorage()
    {
        $total = 0;
        $total += Subscription::app_instance($this->app_instance)->setting('base.storage');
        foreach ($this->additionalStorage() as $additional_storage) {
            if ($additional_storage) {
                $total += $additional_storage->quota();
            }
        }
        $total += $this->userStorage();

        return $total;
    }

    public function updateQuota()
    {
        $calculate_total = $this->calculateTotalAppStorage();

        $this->app_instance->updateSetting('storage_quota', $calculate_total);
        $storage_quota = $calculate_total;

        return $storage_quota;
    }

    public function additionalStorage()
    {
        $organization = $this->organization;

        // Get additional group storage
        $additional_group_storage = $organization->additional_storage()->where('entity', 'group')->where('app_instance_id', $this->app_instance->id)->get();
        foreach ($additional_group_storage as $storage) {
            $this->additional_storage[] = new AdditionalStorageService($this->app_instance->organization, 'group', $storage->name, $this->app_instance);
        }

        // Get additional user storage
        $additional_user_storage = $organization->additional_storage()->where('entity', 'user')->where('app_instance_id', $this->app_instance->id)->get();
        foreach ($additional_user_storage as $storage) {
            $this->additional_storage[] = new AdditionalStorageService($this->app_instance->organization, 'user', $storage->name, $this->app_instance);
        }

        return $this->additional_storage;
    }

    public function userStorage()
    {
        $storage_per_standard_user = Subscription::app_instance($this->app_instance)->setting('standard.storage');
        $storage_per_basic_user = Subscription::app_instance($this->app_instance)->setting('basic.storage');
        $standard_users = AccountManager::users()->appStandardUsers($this->app_instance);
        $basic_users = AccountManager::users()->appBasicUsers($this->app_instance);

        return (count($standard_users) * $storage_per_standard_user) + (count($basic_users) * $storage_per_basic_user);
    }
}
