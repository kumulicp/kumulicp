<?php

namespace App\Integrations\AccountManagers\Database;

use App\AppInstance;
use App\AppRole;
use App\Contracts\AccountManager\PermissionsContract;
use App\Enums\AccessType;
use App\Organization;
use App\Support\AccountManager\PermissionsManager;
use App\Support\AccountManager\UserManager;
use App\User;
use Illuminate\Support\Arr;

class Permissions extends PermissionsManager implements PermissionsContract
{
    private $roles = [];

    private $add_application = null;

    private $remove_application = null;

    private $permissions = [];

    public function __construct(public UserManager $user) {}

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

    public function hasControlPanelAccess()
    {
        return $this->user->is_allowed;
    }

    public function addControlPanelAccess(?User &$user = null, ?Organization $organization = null, bool $verified = false)
    {
        $this->user->assignRole('organization_admin');
        $this->user->is_allowed = true;
        $this->user->save();

        $this->updateUserAccessType();

        if ($organization) {
            $user = $this->user->get();
            $user->organization()->associate($organization);
            if ($verified) {
                $user->email_verified_at = now();
            }
            $user->save();
        }

        Arr::set($this->changes, 'access.control_panel', [
            'access' => true,
            'application' => env('APP_NAME'),
        ]);

        return $this;
    }

    public function removeControlPanelAccess()
    {
        $this->user->removeRole('organization_admin');
        $this->user->is_allowed = false;
        $this->user->save();

        $this->updateUserAccessType();

        Arr::set($this->changes, 'access.control_panel', [
            'access' => false,
            'application' => env('APP_NAME'),
        ]);

        return $this;
    }

    public function addBillingManagerAccess()
    {
        $this->user->assignRole('billing_manager');

        Arr::set($this->changes, 'access.control_panel', [
            'access' => true,
            'application' => env('APP_NAME'),
        ]);

        return $this;
    }

    public function removeBillingManagerAccess()
    {
        $this->user->removeRole('billing_manager');

        Arr::set($this->changes, 'access.control_panel', [
            'access' => false,
            'application' => env('APP_NAME'),
        ]);

        return $this;
    }

    public function hasControlPanelAdminAccess()
    {
        return $this->user->hasRole('control_panel_admin');
    }

    public function addControlPanelAdminAccess(?User &$user = null)
    {
        $this->user->assignRole('control_panel_admin');
        $user = $this->user->get();
        $user->email_verified_at = now();
        $user->save();

        $this->updateUserAccessType();

        Arr::set($this->changes, 'access.control_panel_admin', [
            'access' => true,
            'application' => env('APP_NAME').' '.__('labels.admin'),
        ]);
    }

    public function removeControlPanelAdminAccess()
    {
        $this->user->removeRole('control_panel_admin');

        $this->updateUserAccessType();

        Arr::set($this->changes, 'access.control_panel_admin', [
            'access' => false,
            'application' => env('APP_NAME').' '.__('labels.admin'),
        ]);
    }
}
