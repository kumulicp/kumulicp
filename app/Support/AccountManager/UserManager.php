<?php

namespace App\Support\AccountManager;

use App\AppInstance;
use App\AppRole;
use App\Enums\AccessType;
use App\Services\AdditionalStorageService;
use App\Support\Facades\Subscription;

abstract class UserManager
{
    abstract public function attribute(string $attribute, string $type = 'string');

    abstract public function organization();

    abstract public function permissions();

    abstract public function userAccessType();

    abstract public function appUserAccessType(AppInstance $app_instance);

    abstract public function hasAppRole(AppInstance $app_instance, AppRole $role);

    abstract public function allUserApps();

    /**
     * Sets attributes on the user, by the names the panel uses (first_name, email, ...). Call
     * save() to keep them.
     */
    abstract public function update(array $data);

    abstract public function save();

    /**
     * Removes the user. Both account managers have always had it, and an administrator removing someone
     * relies on it: it is here so that what holds a user of either kind can call it.
     */
    abstract public function delete();

    public function appStorage(AppInstance $app_instance)
    {
        $subscription = Subscription::app_instance($app_instance);
        $additional_storage = new AdditionalStorageService($app_instance->organization, 'user', $this->attribute('username'), $app_instance);
        $access_type = $this->appUserAccessType($app_instance);
        $base_storage = $subscription->userStorage($access_type);

        return $base_storage + $additional_storage->quota();
    }

    public function isInitiated()
    {
        // If new user code doesn't exist, the user has been initiated which, at the moment, means they have set their password.
        $new_user_code = $this->organization()->new_user_codes()->where('username', $this->attribute('username'))->first();

        return ! $new_user_code || $new_user_code->activated === 1;
    }

    public function accessTypes(string $access_type): AccessType
    {
        $access_types = [
            'standard' => AccessType::STANDARD,
            'basic' => AccessType::BASIC,
            'minimal' => AccessType::MINIMAL,
            'none' => AccessType::NONE,
        ];

        return $access_types[$access_type];
    }
}
