<?php

namespace App\Integrations\AccountManagers\Ldap;

use App\Integrations\AccountManagers\Ldap\Seeders\DemoSeeder;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Admin;
use App\Ldap\Models\Group;
use App\Ldap\Models\Organization as LdapOrganization;
use App\Ldap\Models\OrganizationalUnit;
use App\Organization;

class AccountsInterface
{
    public function create(Organization $organization)
    {
        $org_dn = Dn::create($organization);
        if (! $org = LdapOrganization::find($org_dn)) {
            $org = new LdapOrganization;
            $org->o = $organization->slug;
            $org->description = $organization->description;
            if ($organization->zipcode) {
                $org->postalCode = $organization->zipcode;
            }
            if ($organization->state) {
                $org->st = $organization->state;
            }
            if ($organization->street) {
                $org->street = $organization->street;
            }
            if ($organization->phone_number) {
                $org->telephoneNumber = $organization->phone_number;
            }
            $org->setDn($org_dn);
            $org->save();
        }

        if (! $org = Admin::find('cn=admin,'.$org_dn)) {
            $admin = new Admin;
            $admin->cn = 'admin';
            $admin->userPassword = '{CRYPT}'.crypt($organization->secretpw, '$1$3nc8yp7$');
            $admin->inside($org_dn);
            $admin->save();
        }

        $this->ou($organization, 'users');
        $this->ou($organization, 'applications');
        $this->ou($organization, 'emails');
        $this->ou($organization, 'domains');
        $this->ou($organization, 'controlcenter');

        $organization_group = Dn::create('server', 'controlPanelAccess', 'orgAdmin');

        $org_admin = Group::find($organization_group);

        if (! $org_admin) {
            $org_admin = new Group;
            $org_admin->setAttribute('cn', 'Organization Admin');
            $org_admin->setAttribute('description', 'Organization Admin');
            $org_admin->setAttribute('member', $org_dn);
            $org_admin->setDn($organization_group);
            $org_admin->save();
        }

        return $this->account($organization);
    }

    public function account(Organization $organization)
    {
        return new AccountInterface($organization);
    }

    public function users(?Organization $organization = null)
    {
        return new UsersInterface($organization);
    }

    public function groups()
    {
        return new GroupsInterface;
    }

    public function seeder($name)
    {
        if ($name == 'demo') {
            DemoSeeder::run();
        }
    }

    private function ou(Organization $organization, $ou)
    {
        $find = OrganizationalUnit::find(Dn::create($organization, $ou));
        if (! $find) {
            $orgUnit = new OrganizationalUnit;
            $orgUnit->ou = $ou;
            $orgUnit->setDn(Dn::create($organization, $ou));
            $orgUnit->save();

            return $orgUnit;
        }
    }
}
