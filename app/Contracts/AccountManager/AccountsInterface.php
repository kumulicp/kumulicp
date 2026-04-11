<?php

namespace App\Contracts\AccountManager;

use App\Organization;

interface AccountsInterface
{
    public function create($data);

    public function account(Organization $organization);

    public function users();

    public function groups();

    public function seeder($name);
}
