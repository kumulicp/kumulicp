<?php

namespace App\Integrations\AccountManagers\Database;

use App\Contracts\AccountManager\AccountManagerContract;
use App\User;
use Spatie\Permission\Models\Role;

class AccountManagerInterface implements AccountManagerContract
{
    public function accounts()
    {
        return new AccountsInterface;
    }

    public function initiate()
    {
        Role::create(['name' => 'control_panel_admin']);
        Role::create(['name' => 'organization_admin']);
        Role::create(['name' => 'billing_manager']);
    }

    // Return username
    public function checkUsername(string $username)
    {
        return User::where('username', $username)->first()?->username;
    }

    // Return username
    public function checkEmail(string $email)
    {
        return User::where('email', $email)->first()?->username;
    }

    public function testConnection()
    {
        return true;
    }
}
