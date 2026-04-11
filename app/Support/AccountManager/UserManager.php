<?php

namespace App\Support\AccountManager;

use App\AppInstance;
use App\Enums\AccessType;
use App\Services\AdditionalStorageService;
use App\Support\Facades\Subscription;

class UserManager
{
    public function appStorage(AppInstance $app_instance)
    {
        $subscription = Subscription::app_instance($app_instance);
        $additional_storage = new AdditionalStorageService($app_instance->organization, 'user', $this->attribute('username'), $app_instance);
        $access_type = $this->appUserAccessType($app_instance);
        $base_storage = $subscription->setting("{$access_type}.storage");

        return $base_storage + $additional_storage->quota();
    }

    public function isInitiated()
    {
        // If new user code doesn't exist, the user has been initiated which, at the moment, means they have set their password.
        $new_user_code = $this->organization()->new_user_codes()->where('username', $this->attribute('username'))->first();

        return ! $new_user_code || ($new_user_code && $new_user_code->activated === 1);
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
