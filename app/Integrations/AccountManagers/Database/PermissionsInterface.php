<?php

namespace App\Integrations\AccountManagers\Database;

use App\AppInstance;
use App\AppRole;
use App\Enums\AccessType;
use App\Support\AccountManager\PermissionsManager;
use App\Support\AccountManager\UserManager;

class PermissionsInterface extends PermissionsManager
{
    private $roles = [];

    private $add_application = null;

    private $remove_application = null;

    private $permissions = [];

    public function __construct(public UserManager $user) {}

    private function account()
    {
        return new AccountInterface($this->organization);
    }

    public function roles()
    {
        return $this->user->roles;
    }

    public function groups()
    {
        return $this->user->groups;
    }

    public function appPermissions(AppInstance $app_instance)
    {
        return null;
    }

    public function updateUserAccessType()
    {
        if ($this->user->hasRole(['control_panel_admin', 'organization_admin'])) {
            $this->user->access_type = AccessType::STANDARD;
        } else {
            $this->user->access_type = AccessType::NONE;
        }

        $this->user->save();
    }

    public function updateAppRoles(AppInstance $app_instance, array $roles = []) {}

    public function addAppRole(AppInstance $app_instance, AppRole $role) {}

    public function removeAppRole(AppInstance $app_instance, AppRole $role) {}

    public function hasAppStandardAccess(AppInstance $app_instance) {}

    public function addApplication(AppInstance $app_instance, $type = null) {}

    public function removeApplication(AppInstance $app_instance) {}
}
