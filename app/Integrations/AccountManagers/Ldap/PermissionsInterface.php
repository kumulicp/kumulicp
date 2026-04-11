<?php

namespace App\Integrations\AccountManagers\Ldap;

use App\AppInstance;
use App\AppRole;
use App\Contracts\AccountManager\PermissionsContract;
use App\Ldap\Actions\Dn;
use App\Ldap\LdapSupport;
use App\Ldap\Models\EmailUser;
use App\Ldap\Models\Group;
use App\Ldap\Models\OrganizationalUnit;
use App\Ldap\Models\User;
use App\Organization;
use App\Support\AccountManager\PermissionsManager;
use App\Support\AccountManager\UserManager;
use Illuminate\Support\Arr;
use LdapRecord\Models\Attributes\DistinguishedName;

class PermissionsInterface extends PermissionsManager implements PermissionsContract
{
    private $roles = [];

    private $organization;

    public $user;

    public function __construct(UserManager $user)
    {
        $this->organization = $user->organization();
        if (is_a(EmailUser::class, $user->user)) {
            $user = User::find($user->getDn());
        }

        $this->user = $user;
    }

    public function roles()
    {
        $groups = $this->user->groups()->get();
        $applications_ou = OrganizationalUnit::find(Dn::create($this->organization, 'applications'));
        foreach ($groups as $group) {
            if ($group->isDescendantOf($applications_ou) && $group->getParentDn() != $applications_ou->getDn()) {
                $dn = DistinguishedName::make($group->getParentDn());
                if ($app_name = $this->appIdByRole($group->roleName())) {
                    $this->roles[$app_name][] = $group->roleName();
                }
            }
        }

        return $this->roles;
    }

    public function appPermissions(AppInstance $app_instance)
    {
        $app_dn = Dn::create($app_instance->organization, 'applications', $app_instance->name);
        $permissions = Group::find($app_dn);

        if ($permissions) {
            if (count($permissions->descendants()->get()) > 0) {
                return $permissions->descendants()->get();
            } else {
                return $permissions;
            }
        }

        return null;
    }

    public function updateAppRoles(AppInstance $app_instance, array $roles = [])
    {
        $app_name = LdapSupport::getAppInstanceID($app_instance);
        $version = $app_instance->version;

        // If no roles given, automatically use default user roles
        if (count($roles) == 0) {
            $user_groups = $version->defaultUserRoles();

            foreach ($user_groups as $group) {
                $group = Group::find(Dn::create($app_instance->organization, 'applications', [$group->app_slug($app_instance), $app_name]));

                if ($group) {
                    $roles[] = $group->appRole();
                }
            }
        }

        $app_roles = $app_instance->application->roles;

        foreach ($roles as $role) {
            $this->addAppRole($app_instance, $role);
        }

        foreach ($app_roles as $role) {
            if (! in_array($role, $roles)) {
                $this->removeAppRole($app_instance, $role);
            }
        }
    }

    public function addAppRole(AppInstance $app_instance, AppRole $role)
    {
        $role_group = LdapSupport::getAppRoleGroup($app_instance, $role);

        if ($role_group && ! $this->user->groups()->exists($role_group)) {
            $this->user->groups()->attach($role_group);
            Arr::set($this->roles, $role->slug, $role_group->getDn());

            $this->addChange('add', $app_instance, $role);
        }
    }

    public function removeAppRole(AppInstance $app_instance, AppRole $role)
    {
        $role_group = LdapSupport::getAppRoleGroup($app_instance, $role);

        if ($role_group && $this->user->groups()->exists($role_group)) {
            $this->user->groups()->detach($role_group);
            Arr::set($this->roles, $role, $role_group->getDn());

            $this->addChange('remove', $app_instance, $role);
        }
    }

    public function hasAppStandardAccess(AppInstance $app_instance)
    {
        $app_name = $this->LdapSupport::getAppInstanceID($app_instance);
        $app_role_count = $this->user->groups()->in(Dn::create($app_instance->organization, 'applications', $app_name))->count();

        return $app_instance->application->access_type == 'standard' && $app_role_count > 0;
    }

    /*
     *
     * Misc Methods
     *
     */

    public function updateUserAccessType()
    {
        $type = 'none';
        // If user is org admin, automatically set as standard user
        if ($this->hasControlPanelAccess()) {
            $type = 'standard';
        } else {
            $roles = $this->user->groups()->in(Dn::create($this->organization, 'applications'))->get();
            foreach ($roles as $role) {
                if ($role->appRole()) {
                    if ($type != 'basic' && $role->appRoleAccessType() == 'minimal') {
                        $type = 'minimal';
                    } elseif ($type != 'standard' && $role->appRoleAccessType() == 'basic') {
                        $type = 'basic';
                    } elseif ($role->appRoleAccessType() == 'standard') {
                        $type = 'standard';
                        break;
                    }
                }
            }
        }

        $this->user->setAttribute('employeeType', $type);
        $this->user->save();
    }

    /**
     * Control Panel Roles
     */
    public function hasControlPanelAccess()
    {
        return $this->user->groups()->exists(LdapSupport::orgAdminGroup());
    }

    public function addControlPanelAccess(?\App\User &$user = null, ?Organization $organization = null, bool $verified = false)
    {
        $organization = ($organization?->is($this->organization) || $organization?->parent_organization_id === $this->organization->id) ? $organization : $this->organization;
        $guid = LdapSupport::guid($this->user->get());

        if ($user) {
            $user->guid = $guid;
            $user->save();
        } else {
            // If user trashed, restore
            $user = \App\User::withTrashed()->where('guid', $guid)->orWhere('email', $this->user->getFirstAttribute('mail'))->first();
            if ($user && $user->trashed()) {
                $user->restore();
            }
            // If user doesn't exist in database, create user
            elseif (! $user) {
                $user = new \App\User;
                $user->name = $this->user->getFirstAttribute('displayName');
                $user->email = $this->user->getFirstAttribute('mail');
                $user->guid = $guid;
                $user->username = $this->user->getFirstAttribute('cn');
                $user->first_name = $this->user->getFirstAttribute('givenName');
                $user->last_name = $this->user->getFirstAttribute('sn');
            }
        }
        if ($verified) {
            $user->email_verified_at = now();
        }
        $user->guid = $guid;
        $user->organization()->associate($organization);
        $user->is_allowed = true;
        $user->save();

        LdapSupport::orgAdminGroup()->members()->attach($this->user->get());

        // Update user type; used by plan settings to determine which users will add to the price
        $this->updateUserAccessType();

        $this->changes['added'][] = [
            'role' => [
                'id' => 'access',
                'label' => 'Access',
                'description' => 'Access',
                'role_group' => config('app.name'),
            ],
            'application' => config('app.name'),
        ];

        return $this;
    }

    public function removeControlPanelAccess()
    {
        $db_user = \App\User::where('guid', LdapSupport::guid($this->user->get()))->delete();

        LdapSupport::orgAdminGroup()->members()->detach($this->user->get());

        // Update user type; used by plan settings to determine which users will add to the price
        $this->updateUserAccessType();

        $this->changes['removed'][] = [
            'role' => [
                'id' => 'access',
                'label' => 'Access',
                'description' => 'Access',
                'role_group' => config('app.name'),
            ],
            'application' => config('app.name'),
        ];

        return $this;
    }

    public function addBillingManagerAccess()
    {
        LdapSupport::billingManagersGroup($this->organization)->members()->attach($this->user->get());

        Arr::set($this->changes, 'access.control_panel', [
            'access' => true,
            'application' => env('APP_NAME'),
        ]);

        return $this;
    }

    public function removeBillingManagerAccess()
    {
        LdapSupport::billingManagersGroup($this->organization)->members()->detach($this->user->get());

        Arr::set($this->changes, 'access.control_panel', [
            'access' => false,
            'application' => env('APP_NAME'),
        ]);

        return $this;
    }

    public function hasControlPanelAdminAccess()
    {
        $admin = Group::find(Dn::create('server', 'controlPanelAccess', 'admin'));

        return $this->user->groups()->exists($admin);
    }

    public function addControlPanelAdminAccess(?\App\User &$user = null)
    {
        $admin_group = Dn::create('server', 'controlPanelAccess', 'admin');
        $this->addControlPanelAccess($user);

        // Add user to admin group
        $admin_group = Group::find($admin_group);

        $admin_group->members()->attach($this->user->get());

        // Update user type; used by plan settings to determine which users will add to the price
        $this->updateUserAccessType();

        Arr::set($this->changes, 'access.control_panel_admin', [
            'access' => true,
            'application' => env('APP_NAME').' '.__('labels.admin'),
        ]);
    }

    public function removeControlPanelAdminAccess()
    {
        $organization = Group::find(Dn::create('server', 'controlPanelAccess', 'admin'));
        $organization->members()->detach($this->user->get());

        // Update user type; used by plan settings to determine which users will add to the price
        $this->updateUserAccessType();

        Arr::set($this->changes, 'access.control_panel_admin', [
            'access' => false,
            'application' => env('APP_NAME').' '.__('labels.admin'),
        ]);

        return $organization;
    }
}
