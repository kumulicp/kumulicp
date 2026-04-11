<?php

namespace App\Integrations\AccountManagers\Database;

use App\Group;
use App\Services\AdditionalStorageService;
use App\Support\AccountManager\GroupManager;
use App\Support\Facades\Organization;
use App\User;

class GroupInterface extends GroupManager
{
    private $organization;

    public function __construct(private Group $group)
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
            $user = User::where('username', $manager)->first();
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
            $user = User::where('username', $member)->first();
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
        $organization = Organization::account();
        if ($category == $this->categoryName()) {
            return;
        }

        $group_category_ou = OrganizationalUnit::find(Dn::create($organization, [$category, 'groups']));

        if (! $group_category_ou) {
            $group_category_ou = new OrganizationalUnit;
            $group_category_ou->ou = $category;
            $group_category_ou->setDn(Dn::create($organization, [$category, 'groups']));
            $group_category_ou->save();
        }

        if ($new_category = OrganizationalUnit::find(Dn::create($this->organization, [$category, 'groups']))) {
            $this->group->move($group_category_ou);
        }
    }

    public function updateQuota(AppInstance $app_instance, $quantity)
    {
        if ($this->additionalStorage()) {
            if ($this->additional_strorage) {
                $this->additional_storage->updateQuantity($quantity);
            }
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

    private function auto_save()
    {
        if ($this->auto_save) {
            $this->group->save();
        }
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
