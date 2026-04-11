<?php

namespace App\Contracts\AccountManager;

interface GroupsInterface
{
    public function add($data);

    public function find(string $group_name, ?string $category = null);

    public function all();

    public function collect(?string $category = null);

    public function count(?string $category = null);

    public function categories();
}
