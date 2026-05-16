<?php

namespace App\Integrations\AccountManagers\Database;

use App\Contracts\AccountManager\AccountContract;
use App\Organization;

class Account implements AccountContract
{
    public function __construct(private Organization $organization) {}

    public function update($data) {}

    public function users()
    {
        return new Users($this->organization);
    }

    public function groups()
    {
        return new Groups;
    }

    public function destroy() {}
}
