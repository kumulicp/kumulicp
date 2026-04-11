<?php

namespace App\Integrations\AccountManagers\Ldap;

use App\Ldap\Actions\Dn;
use App\Ldap\Models\Group;
use App\Ldap\Models\OrganizationalUnit;
use App\Support\Facades\Organization;

class GroupsInterface
{
    public function add($data)
    {
        $organization = Organization::account();
        // Check if groups ou exists and create if not
        $group_ou = OrganizationalUnit::find(Dn::create($organization, 'groups'));

        if (! $group_ou) {
            $group_ou = new OrganizationalUnit;
            $group_ou->ou = 'groups';
            $group_ou->setDn(Dn::create($organization, 'groups'));
            $group_ou->save();
        }

        $group_category_ou = OrganizationalUnit::find(Dn::create($organization, [$data['category'], 'groups']));

        if (! $group_category_ou) {
            $group_ou = new OrganizationalUnit;
            $group_ou->ou = $data['category'];
            $group_ou->setDn(Dn::create($organization, [$data['category'], 'groups']));
            $group_ou->save();
        }

        $group = new Group;
        $group->setAttribute('cn', $data['name']);
        $group->setAttribute('description', $data['name']);
        $group->setAttribute('member', Dn::create($organization));
        $group->setDn(Dn::create($organization, [$data['category'], 'groups'], $data['name']));
        $group->save();

        return $this->get($group);
    }

    public function find(string $group_name, ?string $category = null)
    {
        $organization = Organization::account();
        if ($category) {
            $group = Group::find(Dn::create($organization, [$category, 'groups'], $validatedData['name']));
        } else {
            $group = Group::in(Dn::create($organization, 'groups'))->where('cn', $group_name)->first();
        }

        if ($group) {
            return $this->get($group);
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
        $organization = Organization::account();
        if ($category) {
            return Group::in(Dn::create($organization, [$category, 'groups']))->get()->map(function ($group) {
                return $this->get($group);
            });
        } else {
            return Group::in(Dn::create($organization, 'groups'))->get()->map(function ($group) {
                return $this->get($group);
            });
        }
    }

    public function count(?string $category = null)
    {
        $organization = Organization::account();
        if ($category) {
            return Group::in(Dn::create($organization, [$category, 'groups']))->get()->count();
        } else {
            return Group::in(Dn::create($organization, 'groups'))->get()->count();
        }
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

    public function get(Group $group)
    {
        return new GroupInterface($group);
    }
}
