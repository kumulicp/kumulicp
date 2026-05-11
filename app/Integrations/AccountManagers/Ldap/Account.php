<?php

namespace App\Integrations\AccountManagers\Ldap;

use App\Contracts\AccountManager\AccountContract;
use App\Ldap\Actions\Dn;
use App\Organization;
use LdapRecord\Models\OpenLDAP\Entry;

class Account implements AccountContract
{
    private string $organization_id;

    public function __construct(private Organization $organization)
    {
        $this->organization_id = Dn::create($this->organization);
    }

    public function update($data) {}

    public function users()
    {
        return new Users($this->organization);
    }

    public function groups()
    {
        return new Groups;
    }

    public function destroy()
    {
        if ($org = Entry::find($this->organization_id)) {
            $org->delete($recursive = true);
        }
    }
}
