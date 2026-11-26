<?php

namespace App\Integrations\AccountManagers\Ldap;

use App\AppInstance;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Group as LdapGroup;
use App\Ldap\Models\OrganizationalUnit;
use App\Organization as OrganizationModel;
use App\Services\AdditionalStorageService;
use App\Support\AccountManager\GroupManager;
use App\Support\Facades\Organization;
use App\SuborgUser;

class Group extends GroupManager
{
    private OrganizationModel $organization;

    private $auto_save = true;

    /**
     * The organization is the one the group belongs to, which is not always the active one: a parent
     * manages its suborganizations' groups.
     */
    public function __construct(private LdapGroup $group, ?OrganizationModel $organization = null)
    {
        $this->organization = $organization ?? Organization::account();
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

    /**
     * Who may belong to this group. A suborganization's group only takes its own users, never the parent's
     * or a sibling's. A parent's group takes anyone in its directory, its suborganizations' users included.
     * Parent and suborg users share one `ou=users`, so the DN can't tell them apart, SuborgUser can.
     *
     * @param  array<int, string>  $usernames
     * @return array<int, string>
     */
    private function usernamesThatBelong(array $usernames): array
    {
        if (! $this->organization->parent_organization_id) {
            return $usernames;
        }

        $own = SuborgUser::where('organization_id', $this->organization->id)
            ->whereIn('username', $usernames)
            ->pluck('username')
            ->all();

        return array_values(array_intersect($usernames, $own));
    }

    public function updateManagers(array $managers)
    {
        $manager_dns = [];

        foreach ($this->usernamesThatBelong($managers) as $manager) {
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
        $members_dns = [];
        foreach ($this->usernamesThatBelong($members) as $member) {
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

            $this->group->rename('cn='.Dn::escape($name));
        }
    }

    public function updateCategory($category)
    {
        if ($category == $this->categoryName()) {
            return;
        }

        $category_dn = Dn::group($this->organization, $category);
        $group_category_ou = OrganizationalUnit::find($category_dn);

        if (! $group_category_ou) {
            $group_category_ou = new OrganizationalUnit;
            $group_category_ou->ou = $category;
            $group_category_ou->setDn($category_dn);
            $group_category_ou->save();
        }

        $this->group->move($group_category_ou);
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
