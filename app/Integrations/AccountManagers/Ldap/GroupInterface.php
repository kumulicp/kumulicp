<?php

namespace App\Integrations\AccountManagers\Ldap;

use App\Ldap\Actions\Dn;
use App\Ldap\Models\Group;
use App\Ldap\Models\OrganizationalUnit;
use App\Services\AdditionalStorageService;
use App\Support\AccountManager\GroupManager;
use App\Support\Facades\Organization;

class GroupInterface extends GroupManager
{
    private $organization;

    public function __construct(private Group $group)
    {
        $this->organization = Organization::account();
    }

    public function attribute($attribute)
    {
        return $this->group->getFirstAttribute($this->keyMap($attribute));
    }

    public function name()
    {
        return $this->group->getFirstAttribute('description');
    }

    public function category()
    {
        return OrganizationalUnit::find($this->group->getParentDn());
    }

    public function categoryName()
    {
        return $this->category()->getFirstAttribute('ou');
    }

    public function managers()
    {
        return $this->group->managers()->get();
    }

    public function managerNames()
    {
        $managers = $this->managers();

        return $managers->map(function ($manager) {
            return $manager->getFirstAttribute('cn');
        });
    }

    public function members()
    {
        $members = [];
        foreach ($this->group->members()->get() as $member) {
            $id = $member->getFirstAttribute('cn');
            if ($id) {
                $members[] = $id;
            }
        }

        return $members;
    }

    public function updateManagers(array $managers)
    {
        $manager_dns = [];

        foreach ($managers as $manager) {
            $manager_dns[] = Dn::create($this->organization, 'users', $manager);
        }

        $this->group->setAttribute('owner', $manager_dns);
        $this->auto_save();
    }

    public function updateMembers(array $members)
    {
        $managers = $this->managers()->map(function ($manager) {
            return $manager->getDn();
        });

        // Convert member names to DN
        foreach ($members as $member) {
            $members_dns[] = Dn::create($this->organization, 'users', $member);
        }

        $base_member = [Dn::create($this->organization)];
        $members = array_merge($base_member, $members_dns, $managers->toArray());
        $this->group->setAttribute('member', array_unique($members));
        $this->auto_save();
    }

    // Update to new name
    public function updateName($name)
    {
        if ($name != $this->group->getFirstAttribute('cn')) {
            $this->group->setAttribute('cn', $name);
            $this->group->setAttribute('description', $name);

            $this->auto_save();

            $this->group->rename("cn=$name");
        }
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

    public function keyMap($key)
    {
        $keys = [
            'slug' => 'cn',
            'name' => 'description',
        ];

        return $keys[$key];
    }
}
