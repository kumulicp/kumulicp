<?php

namespace App\Integrations\AccountManagers\Database;

use App\AppInstance;
use App\AppRole;
use App\Contracts\AccountManager\PermissionsContract;
use App\Enums\AccessType;
use App\Integrations\AccountManagers\Database\User as DatabaseUser;
use App\Organization;
use App\Support\AccountManager\PermissionsManager;
use App\Support\AccountManager\UserManager;
use App\User;
use Illuminate\Support\Arr;

class Permissions extends PermissionsManager implements PermissionsContract
{
    public UserManager $user;

    private DatabaseUser $databaseUser;

    public function __construct(DatabaseUser $user)
    {
        $this->user = $user;
        $this->databaseUser = $user;
    }

    public function roles()
    {
        return $this->databaseUser->roles;
    }

    public function groups()
    {
        return $this->databaseUser->groups;
    }

    public function appPermissions(AppInstance $app_instance)
    {
        return null;
    }

    public function updateUserAccessType()
    {
        if ($this->databaseUser->hasRole(['control_panel_admin', 'organization_admin'])) {
            $this->databaseUser->access_type = AccessType::STANDARD;
        } else {
            $this->databaseUser->access_type = AccessType::NONE;
        }

        $this->databaseUser->save();
    }

    public function updateAppRoles(AppInstance $app_instance, array $roles = []) {}

    public function addAppRole(AppInstance $app_instance, AppRole $role) {}

    public function removeAppRole(AppInstance $app_instance, AppRole $role) {}

    public function hasAppStandardAccess(AppInstance $app_instance) {}

    public function addApplication(AppInstance $app_instance, $type = null) {}

    public function removeApplication(AppInstance $app_instance) {}

    public function hasControlPanelAccess()
    {
        return $this->databaseUser->is_allowed;
    }

    public function addControlPanelAccess(?User &$user = null, ?Organization $organization = null, bool $verified = false)
    {
        $this->databaseUser->assignRole('organization_admin');
        $this->databaseUser->is_allowed = true;
        $this->databaseUser->save();

        $this->updateUserAccessType();

        if ($organization) {
            $user = $this->databaseUser->get();
            $user->organization()->associate($organization);
            if ($verified) {
                $user->email_verified_at = now();
            }
            $user->save();
        }

        Arr::set($this->changes, 'access.control_panel', [
            'access' => true,
            'application' => config('app.name'),
        ]);

        return $this;
    }

    public function removeControlPanelAccess()
    {
        $this->databaseUser->removeRole('organization_admin');
        $this->databaseUser->is_allowed = false;
        $this->databaseUser->save();

        $this->updateUserAccessType();

        Arr::set($this->changes, 'access.control_panel', [
            'access' => false,
            'application' => config('app.name'),
        ]);

        return $this;
    }

    public function addBillingManagerAccess()
    {
        $this->databaseUser->assignRole('billing_manager');

        Arr::set($this->changes, 'access.control_panel', [
            'access' => true,
            'application' => config('app.name'),
        ]);

        return $this;
    }

    public function removeBillingManagerAccess()
    {
        $this->databaseUser->removeRole('billing_manager');

        Arr::set($this->changes, 'access.control_panel', [
            'access' => false,
            'application' => config('app.name'),
        ]);

        return $this;
    }

    public function hasControlPanelAdminAccess()
    {
        return $this->databaseUser->hasRole('control_panel_admin');
    }

    public function addControlPanelAdminAccess(?User &$user = null)
    {
        $this->databaseUser->assignRole('control_panel_admin');
        $user = $this->databaseUser->get();
        $user->email_verified_at = now();
        $user->save();

        $this->updateUserAccessType();

        Arr::set($this->changes, 'access.control_panel_admin', [
            'access' => true,
            'application' => config('app.name').' '.__('labels.admin'),
        ]);
    }

    public function removeControlPanelAdminAccess()
    {
        $this->databaseUser->removeRole('control_panel_admin');

        $this->updateUserAccessType();

        Arr::set($this->changes, 'access.control_panel_admin', [
            'access' => false,
            'application' => config('app.name').' '.__('labels.admin'),
        ]);
    }
}
