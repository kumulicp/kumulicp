<?php

namespace App\Integrations\AccountManagers\Ldap;

use App\Contracts\AccountManager\AccountManagerContract;
use App\Ldap\Actions\Dn;
use App\Ldap\LdapSupport;
use App\Ldap\Models\Group;
use App\Ldap\Models\Organization;
use App\Ldap\Models\OrganizationalUnit;
use App\Ldap\Models\User;

class AccountManagerInterface implements AccountManagerContract
{
    public function accounts()
    {
        return new AccountsInterface;
    }

    public function initiate()
    {
        $server_dn = Dn::create('server');
        if (! $server = Organization::find($server_dn)) {
            $server = new Organization;
            $server->setAttribute('o', 'server');
            $server->setDn($server_dn);
            $server->save();
        }

        $ou_dn = Dn::create('server', 'controlPanelAccess');
        if (! $ou = OrganizationalUnit::find($ou_dn)) {
            $ou = new OrganizationalUnit;
            $ou->ou = 'controlPanelAccess';
            $ou->setDn($ou_dn);
            $ou->save();
        }

        $admin_group_dn = Dn::create('server', 'controlPanelAccess', 'admin');

        if (! $admin_group = Group::find($admin_group_dn)) {
            $admin_group = new Group;
            $admin_group->setAttribute('cn', 'admin');
            $admin_group->setAttribute('description', 'Server Admin');
            $admin_group->setAttribute('member', $server_dn);
            $admin_group->setDn($admin_group_dn);
            $admin_group->save();
        }
    }

    // Return username
    public function checkUsername(string $username)
    {
        return User::where('cn', $username)->first()?->getFirstAttribute('cn');
    }

    // Return username
    public function checkEmail(string $email)
    {
        return User::where('mail', $email)->first()?->getFirstAttribute('cn');
    }

    public function testConnection()
    {
        return LdapSupport::testConnection();
    }
}
