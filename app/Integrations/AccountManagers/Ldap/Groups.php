<?php

namespace App\Integrations\AccountManagers\Ldap;

use App\Ldap\Actions\Dn;
use App\Ldap\Models\Group as LdapGroup;
use App\Ldap\Models\OrganizationalUnit;
use App\Organization as OrganizationModel;
use App\Support\Facades\Organization;

/**
 * The groups of one organization.
 *
 * Which organization is the one given, or the active one. A parent's groups and each of its suborganizations'
 * groups sit in separate containers (see Dn::group()), so every lookup here goes through a category container
 * of this organization and never searches the whole `ou=groups` tree, which would also match the suborgs'.
 */
class Groups
{
    private OrganizationModel $organization;

    public function __construct(?OrganizationModel $organization = null)
    {
        $this->organization = $organization ?? Organization::account();
    }

    public function add($data)
    {
        $this->ensureContainers($data['category']);

        $group = new LdapGroup;
        $group->setAttribute('cn', $data['name']);
        $group->setAttribute('description', $data['name']);
        $group->setAttribute('member', Dn::create($this->organization));
        $group->setDn(Dn::group($this->organization, $data['category'], $data['name']));
        $group->save();

        return $this->get($group);
    }

    public function find(string $group_name, ?string $category = null)
    {
        $group = $this->findModel($group_name, $category);

        return $group ? $this->get($group) : null;
    }

    /**
     * The directory entry of a group of this organization, whichever category it is in.
     */
    public function findModel(string $group_name, ?string $category = null): ?LdapGroup
    {
        $categories = $category ? [$category] : array_keys($this->categories());

        foreach ($categories as $name) {
            $group = LdapGroup::find(Dn::group($this->organization, $name, $group_name));

            if ($group instanceof LdapGroup) {
                return $group;
            }
        }

        return null;
    }

    public function all()
    {
        $groups = [];
        foreach ($this->categories() as $name => $category) {
            $category['groups'] = $this->collect($name)->map(function ($group) {
                return [
                    'slug' => $group->attribute('slug'),
                    'name' => $group->attribute('name'),
                ];
            })->all();
            $groups[] = $category;
        }

        return $groups;
    }

    public function collect(?string $category = null)
    {
        $categories = $category ? [$category] : array_keys($this->categories());
        $groups = collect();

        foreach ($categories as $name) {
            foreach (LdapGroup::in(Dn::group($this->organization, $name))->get() as $group) {
                $groups->push($this->get($group));
            }
        }

        return $groups;
    }

    public function count(?string $category = null)
    {
        return $this->collect($category)->count();
    }

    public function categories()
    {
        return [
            'departments' => [
                'ou' => 'departments',
                'name' => 'Departments',
            ],
            'teams' => [
                'ou' => 'teams',
                'name' => 'Teams',
            ],
            'projects' => [
                'ou' => 'projects',
                'name' => 'Projects',
            ],
            'ministries' => [
                'ou' => 'ministries',
                'name' => 'Ministries',
            ],
            'others' => [
                'ou' => 'others',
                'name' => 'Others',
            ],
        ];
    }

    public function get(LdapGroup $group)
    {
        return new Group($group, $this->organization);
    }

    private function ensureContainers(string $category): void
    {
        foreach (Dn::groupContainers($this->organization, $category) as [$ou, $dn]) {
            if (! OrganizationalUnit::find($dn)) {
                $unit = new OrganizationalUnit;
                $unit->ou = $ou;
                $unit->setDn($dn);
                $unit->save();
            }
        }
    }
}
