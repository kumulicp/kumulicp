<?php

namespace App\Integrations\AccountManagers\Database;

use App\AppInstance;
use App\AppRole;
use App\Group as GroupModel;
use App\Organization;
use App\Support\AccountManager\UserManager;
use App\User as UserModel;
use Illuminate\Support\Facades\Hash;

/**
 * @mixin \App\User
 */
class User extends UserManager
{
    private $permissions;

    private $organization;

    public function __construct(private UserModel $user)
    {
        $this->organization = $user->organization;
    }

    public function organization()
    {
        return $this->organization;
    }

    public function get()
    {
        return $this->user;
    }

    public function account()
    {
        return $this->organization;
    }

    public function attribute(string $attribute, string $type = 'string')
    {
        if ($attribute === 'personal_email') {
            $attribute = 'email';
        }

        return $this->user->$attribute;
    }

    public function databaseUser()
    {
        return $this->user;
    }

    public function permissions()
    {
        return new Permissions($this);
    }

    public function isPassword(string $password)
    {
        return Hash::check($password, $this->user->password);
    }

    // Gathers data about user directly from app
    public function allUserApps()
    {
        return [];
    }

    public function userAccessType()
    {
        return $this->user->access_type;
    }

    public function appUserAccessType(AppInstance $app_instance)
    {
        return 'none';
    }

    public function isUserAccessType(string $type)
    {
        return $this->userAccessType() === $type;
    }

    public function update(array $data)
    {
        foreach ($data as $key => $value) {
            $this->user->$key = $value;
        }
    }

    public function updateOrganization(Organization $organization)
    {
        $this->user->organization()->associate($organization);
        $this->user->save();
    }

    public function canAccessApp(AppInstance $app_instance)
    {
        return false;
    }

    public function listGroups()
    {
        $groups = $this->user->groups;

        return $groups->map(function ($group) {
            return [
                'slug' => $group->slug,
                'name' => $group->name,
            ];
        });
    }

    public function addToGroup(string $groupid)
    {
        $group = GroupModel::where('slug', $groupid)->first();

        if ($group) {
            $this->user->groups()->attach($group);
            $this->user->save();
        }

        return new Group($group);
    }

    public function removeFromGroup($groupid)
    {
        $group = GroupModel::where('slug', $groupid)->first();

        if ($group) {
            $this->user->groups()->detach($group);
            $this->user->save();
        }

        return new Group($group);
    }

    public function hasAppRole(AppInstance $app_instance, AppRole $role)
    {
        return false;
    }

    public function addToDefaultUserGroups(?iterable $appInstances = null) {}

    private function removeFromAllGroups()
    {
        foreach ($this->user->groups as $group) {
            $this->user->groups()->disassociate($group);
        }

        return $this;
    }

    public function delete()
    {
        $this->user->delete();
    }

    public function apps()
    {
        return []; // The database currently has no way to tracking which user has access to which app
    }

    public function __get($property)
    {
        return $this->user->$property;
    }

    public function __set($property, $value)
    {
        $this->user->$property = $value;
    }

    public function __call($method, $args)
    {
        return $this->user->$method(...$args);
    }
}
