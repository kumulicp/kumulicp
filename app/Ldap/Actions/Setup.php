<?php

namespace App\Ldap\Actions;

use App\Ldap\Models\Organization;
use App\Ldap\Models\OrganizationalUnit;
use LdapRecord\Models\OpenLDAP\Entry;

class Setup
{
    public function run()
    {
        $entry = new Entry;

        // Create server org if doesn't yet exist
        if (! $entry->find(Dn::create('server'))) {
            $organization = new Organization;
            $organization->o = 'server';
            $organization->setDn(Dn::create('server'));
            $organization->save();
        }

        if ($entry->find(Dn::create('server'))) {
            if (! $entry->find(Dn::create('server', 'controlPanelAccess'))) {
                $org_cp_access = new OrganizationalUnit;
                $org_cp_access->ou = 'controlPanelAccess';
                $org_cp_access->setDn(Dn::create('server', 'controlPanelAccess'));
                $org_cp_access->save();
            }

            if (! $entry->find(Dn::create('server', 'controlPanelAccess'))) {
                return false;
            }

        } else {
            return false;
        }

        return true;
    }
}
