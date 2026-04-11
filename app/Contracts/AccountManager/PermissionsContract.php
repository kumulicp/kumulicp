<?php

namespace App\Contracts\AccountManager;

use App\AppInstance;
use App\AppRole;
use App\Organization;
use App\User;

interface PermissionsContract
{
    public function roles();

    public function appPermissions(AppInstance $app_instance);

    public function updateAppRoles(AppInstance $app_instance, array $roles = []);

    public function addAppRole(AppInstance $app_instance, AppRole $role);

    public function removeAppRole(AppInstance $app_instance, AppRole $role);

    public function addChange($type, AppInstance $app_instance, AppRole $role);

    public function changes();

    public function addedPermissions();

    public function modifiedPermissions();

    public function removedPermissions();

    public function accessPermissions();

    public function changedRoleGroups($type);

    public function hasChanges();

    public function hasAppStandardAccess(AppInstance $app_instance);

    public function getAppAccessTypes(AppInstance $organization);

    public function hasControlPanelAccess();

    public function addControlPanelAccess(?User &$user = null, ?Organization $organization = null, bool $verified = false);

    public function removeControlPanelAccess();

    public function addBillingManagerAccess();

    public function removeBillingManagerAccess();

    public function updateUserAccessType();

    public function hasControlPanelAdminAccess();

    public function addControlPanelAdminAccess(?User &$user = null);

    public function removeControlPanelAdminAccess();

    public function appIdByRole(string $role);

    public function processRequest($request);
}
