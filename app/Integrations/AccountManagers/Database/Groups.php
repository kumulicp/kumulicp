<?php

namespace App\Integrations\AccountManagers\Database;

use App\Group as GroupModel;
use App\Organization as OrganizationModel;
use App\Support\Facades\Organization;

class Groups
{
    private OrganizationModel $organization;

    /**
     * The groups of the given organization, or of the active one.
     */
    public function __construct(?OrganizationModel $organization = null)
    {
        $this->organization = $organization ?? Organization::account();
    }

    public function add(array $data)
    {
        $group = GroupModel::where('name', $data['name'])->where('organization_id', $this->organization->id)->first();

        if (! $group) {
            $group = new GroupModel;
            $group->organization_id = $this->organization->id;
            $group->slug = $data['name'];
            $group->name = $data['name'];
            $group->category = $data['category'];
            $group->save();
        }

        return new Group($group, $this->organization);
    }

    public function find(string $group_name, ?string $category = null)
    {
        $group = $this->organization->groups()->where('slug', $group_name)->first();

        return $group ? new Group($group, $this->organization) : null;
    }

    public function all()
    {
        $categories = $this->categories();
        foreach ($this->organization->groups as $group) {
            array_push($categories[$group->category]['groups'], [
                'slug' => $group->slug,
                'name' => $group->name,
            ]);
        }

        return $categories;
    }

    public function collect(?string $category = null)
    {
        return $this->organization->groups()->where('category', $category)->get();
    }

    public function count(?string $category = null)
    {
        return $this->organization->groups()->where('category', $category)->count();
    }

    public function categories()
    {
        return [
            'departments' => [
                'ou' => 'departments',
                'name' => 'Departments',
                'groups' => [],
            ],
            'teams' => [
                'ou' => 'teams',
                'name' => 'Teams',
                'groups' => [],
            ],
            'projects' => [
                'ou' => 'projects',
                'name' => 'Projects',
                'groups' => [],
            ],
            'ministries' => [
                'ou' => 'ministries',
                'name' => 'Ministries',
                'groups' => [],
            ],
            'others' => [
                'ou' => 'others',
                'name' => 'Others',
                'groups' => [],
            ],
        ];
    }

    public function get(GroupModel $group)
    {
        return new Group($group, $this->organization);
    }
}
