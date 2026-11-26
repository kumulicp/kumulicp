<?php

namespace App\Integrations\AccountManagers\Database;

use App\AppInstance;
use App\AppRole;
use App\Group as GroupModel;
use App\Mailbox;
use App\Organization;
use App\Support\AccountManager\UserManager;
use App\User as UserModel;
use App\UserRecoveryEmail;
use Illuminate\Support\Facades\Hash;

/**
 * @mixin UserModel
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
        // userAccessType() is an AccessType, never the string: compare what it stands for
        return $this->userAccessType()?->value === $type;
    }

    public function update(array $data)
    {
        foreach ($data as $key => $value) {
            $this->user->$key = $value;
        }
    }

    public function save()
    {
        return $this->user->save();
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

    /**
     * The group with this slug the user may belong to: one of their own organization's or, for a user of a
     * suborganization, one of the parent's. The same name can exist in several organizations.
     */
    private function groupFor(string $groupid): ?GroupModel
    {
        $organization = $this->user->organization;
        $organization_ids = array_filter([$organization?->id, $organization?->parent_organization_id]);

        return GroupModel::where('slug', $groupid)
            ->whereIn('organization_id', $organization_ids)
            ->orderByRaw('organization_id = ? desc', [$organization?->id])
            ->first();
    }

    public function addToGroup(string $groupid)
    {
        $group = $this->groupFor($groupid);

        if ($group) {
            $this->user->groups()->attach($group);
            $this->user->save();
        }

        return new Group($group);
    }

    public function removeFromGroup($groupid)
    {
        $group = $this->groupFor($groupid);

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
        UserRecoveryEmail::where('username', $this->user->username)->delete();
        Mailbox::where('username', $this->user->username)->delete();

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
