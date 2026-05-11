<?php

namespace App\Integrations\AccountManagers\Database;

use App\Organization;
use App\User;
use Spatie\Permission\Models\Role;

class Accounts
{
    public function create(Organization $organization) {}

    public function account(Organization $organization)
    {
        return new Account($organization);
    }

    public function users(?Organization $organization = null)
    {
        return new Users($organization);
    }

    public function groups()
    {
        return new Groups;
    }

    public function seeder($name)
    {
        Role::create(['name' => 'control_panel_admin']);
        Role::create(['name' => 'organization_admin']);
        Role::create(['name' => 'billing_manager']);

        $user = User::find(1);
        $user->assignRole('control_panel_admin');
        $user->assignRole('organization_admin');
    }
}
