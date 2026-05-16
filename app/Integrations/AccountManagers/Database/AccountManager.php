<?php

namespace App\Integrations\AccountManagers\Database;

use App\Contracts\AccountManager\AccountManagerContract;
use App\User;
use Spatie\Permission\Models\Role;

class AccountManager implements AccountManagerContract
{
    public function accounts()
    {
        return new Accounts;
    }

    public function initiate()
    {
        Role::create(['name' => 'control_panel_admin']);
        Role::create(['name' => 'organization_admin']);
        Role::create(['name' => 'billing_manager']);
    }

    public function checkUsername(string $username)
    {
        return User::where('username', $username)->first()?->username;
    }

    public function checkEmail(string $email)
    {
        return User::where('email', $email)->first()?->username;
    }

    public function testConnection()
    {
        return true;
    }
}
