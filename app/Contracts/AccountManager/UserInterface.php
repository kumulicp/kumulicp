<?php

namespace App\Contracts\AccountManager;

use App\AppInstance;

interface UserInterface extends UserManager
{
    public function get();

    public function account();

    public function attribute(string $attribute, string $type = 'string');

    public function permissions();

    public function allUserApps();

    public function userAccessType();

    public function isUserAccessType($type);

    public function updateUserAccessType();

    public function update(array $data);

    public function canAccessApp(AppInstance $app_instance);

    public function hasAppRole(AppInstance $app_instance, string $role);

    public function appStorage(AppInstance $app_instance);

    public function addToDefaultUserGroups();

    private function removeFromAllGroups();

    public function save();

    public function delete();
}
