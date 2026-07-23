<?php

namespace App\Integrations\AccountManagers\Database;

use App\AppInstance;
use App\Group as GroupModel;
use App\Services\AdditionalStorageService;
use App\Support\AccountManager\GroupManager;
use App\Support\Facades\Organization;
use App\User as UserModel;

class Group extends GroupManager
{
    private $organization;

    private $auto_save = true;

    public function __construct(private GroupModel $group)
    {
        $this->organization = Organization::account();
    }

    public function attribute($attribute)
    {
        return $this->group->$attribute;
    }

    public function name()
    {
        return $this->group->name;
    }

    public function categoryName()
    {
        return $this->group->category;
    }

    public function managers()
    {
        return $this->group->members->filter(function ($member) {
            return $member->pivot->role === 'manager';
        });
    }

    public function managerNames()
    {
        return $this->managers()->map(function ($manager) {
            return $manager->username;
        });
    }

    public function members()
    {
        return $this->group->members->filter(function ($member) {
            return $member->pivot->role === 'member';
        })->map(function ($member) {
            return $member->username;
        })->values();
    }

    public function updateManagers(array $managers)
    {
        $members = $this->group->members()->get();
        $this->group->members()->detach($members);
        foreach ($managers as $manager) {
            $user = UserModel::where('username', $manager)->first();
            if ($user) {
                if ($member = $this->group->members()->where('user_id', $user->id)->first()) {
                    if ($member->pivot->role === 'member') {
                        $member->pivot->role = 'manager';
                        $member->pivot->save();
                    }
                } else {
                    $this->group->members()->attach($user, ['role' => 'manager']);
                }
            }
        }
    }

    public function updateMembers(array $members)
    {
        foreach ($members as $member) {
            $user = UserModel::where('username', $member)->first();
            $member = $this->group->members()->where('user_id', $user->id)->first();
            if ($user && ! $member) {
                $this->group->members()->attach($user, ['role' => 'member']);
            }
        }
    }

    // Update to new name
    public function updateName(string $name)
    {
        $this->group->name = $name;
    }

    public function updateCategory($category)
    {
        if ($category == $this->categoryName()) {
            return;
        }

        $this->group->category = $category;
        $this->group->save();
    }

    public function updateQuota(AppInstance $app_instance, $quantity)
    {
        if ($additional_storage = $this->additionalStorage($app_instance)) {
            $additional_storage->updateQuantity($quantity);
        }
    }

    public function additionalStorage(AppInstance $app_instance)
    {
        return new AdditionalStorageService($this->organization, 'group', $this->name(), $app_instance);
    }

    public function allAddtionalStorage()
    {
        return new AdditionalStorageService($this->organization, 'group', $this->name());
    }

    public function delete()
    {
        if ($all_additional_storage = $this->allAddtionalStorage()) {
            $all_additional_storage->delete();
        }

        $this->group->delete();
    }

    public function save()
    {
        $this->group->save();
    }

    public function disableAutoSave()
    {
        $this->auto_save = false;

        return $this;
    }
}
