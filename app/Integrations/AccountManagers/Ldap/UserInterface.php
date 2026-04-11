<?php

namespace App\Integrations\AccountManagers\Ldap;

use App\AppInstance;
use App\AppRole;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\EmailUser;
use App\Ldap\Models\Group;
use App\Ldap\Models\User;
use App\Organization;
use App\SuborgUser;
use App\Support\AccountManager\UserManager;
use App\Support\Facades\Settings;
use App\User as DbUser;
use LdapRecord\Container;
use LdapRecord\Models\OpenLDAP\Entry;

class UserInterface extends UserManager
{
    private $permissions;

    public $organization;

    private $user;

    public function __construct(User|EmailUser $user)
    {
        $this->organization = $user->organization();
        if (is_a(EmailUser::class, $user)) {
            $user = User::find($user->getDn());
        }

        $this->user = $user;
    }

    public function get()
    {
        return $this->user;
    }

    public function organization()
    {
        return $this->organization;
    }

    public function attribute(string $attribute, string $type = 'string')
    {
        $attribute = $this->mapKey($attribute);

        if ($type == 'string') {
            return $this->getFirstAttribute($attribute);
        } elseif ($type == 'array') {
            return $this->attribute($attribute);
        }
    }

    public function databaseUser()
    {
        return DbUser::where('guid', $this->authIdentifier())->first();
    }

    public function permissions()
    {
        return new PermissionsInterface($this);
    }

    public function isPassword(string $password)
    {
        $connection = Container::getDefaultConnection();

        return $connection->auth()->attempt($this->getDn(), $password);
    }

    // Gathers data about user directly from app
    public function allUserApps()
    {
        return $this->user->apps();
    }

    public function userAccessType()
    {
        $access_type = $this->getFirstAttribute('employeeType') ?? 'none';

        return $this->accessTypes($access_type);
    }

    public function appUserAccessType(AppInstance $app_instance)
    {
        $type = 'none';
        $permissions_type = $app_instance->application->permissions_type;
        $app_name = $app_instance->parent_id ? $app_instance->parent->name : $app_instance->name;
        $app_dn = Dn::create($this->organization, 'applications', $app_name);

        $groups = $this->user->groups()->in($app_dn)->get();
        foreach ($groups as $group) {
            $app_role = $group->appRole();
            if ($app_role && $group->application()?->is($app_instance)) {
                if (! in_array($type, ['standard', 'basic']) && $app_role->accessType(app: $app_instance)->value === 'minimal') {
                    $type = 'minimal';
                } elseif ($type !== 'standard' && $app_role->accessType(app: $app_instance)->value === 'basic') {
                    $type = 'basic';
                } elseif ($app_role->accessType(app: $app_instance)->value === 'standard') {
                    $type = 'standard';
                    break;
                }
            }
        }

        return $type;
    }

    public function isUserAccessType($type)
    {
        return $this->userAccessType() == $type;
    }

    public function update(array $data)
    {
        foreach ($data as $key => $value) {
            $key = $this->mapKey($key);
            $this->user->setAttribute($key, $value);
        }
        $this->save();
    }

    public function canAccessApp(AppInstance $app_instance)
    {
        $permissions = $this->permissions()->appPermissions($app_instance);
        if (is_countable($permissions)) {
            foreach ($permissions as $permission) {
                if ($this->user->groups()->exists($permission)) {
                    return true;
                }
            }
        }

        return $this->user->groups()->exists($permissions);
    }

    public function listGroups()
    {
        $groups = $this->user->groups()->in(Dn::create($this->organization, 'groups'))->get();

        return $groups->map(function ($group) {
            return [
                'slug' => $group->getFirstAttribute('cn'),
                'name' => $group->getFirstAttribute('description'),
            ];
        });
    }

    public function addToGroup($groupid)
    {
        $group = Group::in(Dn::create($this->organization, 'groups'))->where('cn', $groupid)->first();

        if ($group && ! $this->user->groups()->exists($group)) {
            $this->user->groups()->attach($group);
        }

        return new GroupInterface($group);
    }

    public function removeFromGroup($groupid)
    {
        $group = Group::in(Dn::create($this->organization, 'groups'))->where('cn', $groupid)->first();

        if ($group && $this->user->groups()->exists($group)) {
            $this->user->groups()->detach($group);
        }

        return new GroupInterface($group);
    }

    public function hasAppRole(AppInstance $app_instance, AppRole $role)
    {
        $app_instance = $app_instance->parent ?? $app_instance;
        $role_dn = Dn::create($app_instance->organization, 'applications', [$role->app_slug($app_instance), $app_instance->name]);

        return $this->user->groups()->exists($role_dn);
    }

    public function addToDefaultUserGroups()
    {
        $apps = [];
        foreach ($this->organization->app_instances as $app) {
            // updateAppRoles will automatically use default app roles if nothing provided.
            $this->permissions()->updateAppRoles($app);
        }
    }

    private function removeFromAllGroups()
    {
        $organization = auth()->user()->organization;
        $applications = Entry::in(Dn::create($organization, 'applications'))->get();

        foreach ($applications as $groups) {
            $group = Group::find($groups);

            if ($group) {
                if ($this->user->groups()->exists($group)) {
                    $this->user->groups()->detach($group);
                }
            }
        }
    }

    public function updateOrganization(Organization $organization)
    {
        $suborg_user = SuborgUser::where('username', $this->attribute('username'))->first();

        if ($organization->parent_organization_id) {
            if ($suborg_user && $suborg_user->organization_id !== $organization->id) {
                $suborg_user->organization()->associate($organization);
                $suborg_user->save();
            } else {
                $suborg_user = new SuborgUser;
                $suborg_user->organization()->associate($organization);
                $suborg_user->username = $this->attribute('username');
                $suborg_user->save();
            }
        } elseif ($suborg_user) {
            $suborg_user->delete();
        }
    }

    public function save()
    {
        $this->user->save();
    }

    public function delete()
    {
        if ($db_user = DbUser::where('guid', $this->user->getFirstAttribute('entryUUID'))->first()) {
            $db_user->forceDelete();
        }

        $this->removeFromAllGroups();
        $this->user->delete();
    }

    public function mapKey($key)
    {

        $keys = [
            'first_name' => Settings::get('ldap_first_name', 'givenName'),
            'last_name' => Settings::get('ldap_last_name', 'sn'),
            'email' => Settings::get('ldap_email', 'mail'),
            'phone_number' => Settings::get('ldap_phone_number', 'telephoneNumber'),
            'username' => Settings::get('ldap_username', 'cn'),
            'personal_email' => Settings::get('ldap_personal_email', 'mail'),
            'name' => Settings::get('ldap_name', 'displayName'),
            'org_email' => Settings::get('ldap_org_email', 'mail'),
            'access_type' => Settings::get('ldap_access_type', 'employeeType'),
            'password' => Settings::get('ldap_password', 'userPassword'),
        ];

        return $keys[$key];
    }

    public function __get($property)
    {
        return $this->user->$property;
    }

    public function __call($method, $args)
    {
        return $this->user->$method(...$args);
    }
}
