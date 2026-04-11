<?php

namespace App\Integrations\AccountManagers\Ldap\Seeders;

use App\Integrations\AccountManagers\Ldap\AccountManagerInterface;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Group;
use App\Support\Facades\Organization;
use App\User;

class DemoSeeder
{
    public static function run()
    {
        $organization = Organization::account();
        $organization_manager = new AccountManagerInterface;
        $organization_manager->initiate();

        $organizations = $organization_manager->accounts();

        $organizations->create($organization);

        $user = $organizations->users()->add([
            'username' => 'demo',
            'first_name' => 'Demo',
            'last_name' => 'User',
            'name' => 'Demo User',
            'phone_number' => '1234567890',
            'email' => 'demo@example.com',
            'password' => 'demouser',
            'employeeType' => 'standard',
        ]);

        $db_user = User::find(1);

        $user->permissions()->addControlPanelAccess($db_user);
        $user->permissions()->addControlPanelAdminAccess($db_user);

        $LdapApp = Group::find(Dn::create($organization, 'applications', 'nextcloud'));
        if (! $LdapApp) {
            $LdapApp = new Group;
            $LdapApp->inside(Dn::create($organization, 'applications'));
            $LdapApp->setAttribute('cn', 'nextcloud');
            $LdapApp->setAttribute('description', 'Nextcloud');
            $LdapApp->member = Dn::create($organization);
            $LdapApp->save();
        }

        $LdapStandardUser = Group::find(Dn::create($organization, 'applications', ['standard', 'nextcloud']));
        if (! $LdapStandardUser) {
            $LdapStandardUser = new Group;
            $LdapStandardUser->inside($LdapApp->getDn());
            $LdapStandardUser->setAttribute('cn', 'standard');
            $LdapStandardUser->setAttribute('description', 'Standard');
            $LdapStandardUser->member = Dn::create($organization);
            $LdapStandardUser->save();
        }

        $LdapBasicUser = Group::find(Dn::create($organization, 'applications', ['basic', 'nextcloud']));
        if (! $LdapBasicUser) {
            $LdapBasicUser = new Group;
            $LdapBasicUser->inside($LdapApp->getDn());
            $LdapBasicUser->setAttribute('cn', 'basic');
            $LdapBasicUser->setAttribute('description', 'Basic');
            $LdapBasicUser->member = Dn::create($organization);
            $LdapBasicUser->save();
        }

        $LdapApp = Group::find(Dn::create($organization, 'applications', 'wordpress'));
        if (! $LdapApp) {
            $LdapApp = new Group;
            $LdapApp->inside(Dn::create($organization, 'applications'));
            $LdapApp->setAttribute('cn', 'wordpress');
            $LdapApp->setAttribute('description', 'Wordpress');
            $LdapApp->member = Dn::create($organization);
            $LdapApp->save();
        }
    }
}
