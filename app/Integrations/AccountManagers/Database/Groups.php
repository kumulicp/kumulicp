<?php

namespace App\Integrations\AccountManagers\Database;

use App\Group as GroupModel;
use App\Support\Facades\Organization;

class Groups
{
    private $organization;

    public function __construct()
    {
        $this->organization = Organization::account();
    }

    public function add(array $data)
    {
        $group = GroupModel::where('name', $data['name'])->first();

        if (! $group) {
            $group = new GroupModel;
            $group->organization_id = $this->organization->id;
            $group->slug = $data['name'];
            $group->name = $data['name'];
            $group->category = $data['category'];
            $group->save();
        }

        return new Group($group);
    }

    public function find(string $group_name, ?string $category = null)
    {
        $group = $this->organization->groups()->where('slug', $group_name)->first();

        return $group ? new Group($group) : null;
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
        return new Group($group);
    }
}
