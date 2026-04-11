<?php

namespace App\Integrations\AccountManagers\Database;

use App\Contracts\AccountManager\AccountContract;
use App\Organization;

class AccountInterface implements AccountContract
{
    public function __construct(private Organization $organization) {}

    public function update($data) {}

    public function users()
    {
        return new UsersInterface($this->organization);
    }

    public function groups()
    {
        return new GroupsInterface;
    }

    public function destroy() {}
}
