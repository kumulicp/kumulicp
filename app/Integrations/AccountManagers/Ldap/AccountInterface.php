<?php

namespace App\Integrations\AccountManagers\Ldap;

use App\Contracts\AccountManager\AccountContract;
use App\Ldap\Actions\Dn;
use App\Organization;
use LdapRecord\Models\OpenLDAP\Entry;

class AccountInterface implements AccountContract
{
    private string $organization_id;

    public function __construct(private Organization $organization)
    {
        $this->organization_id = Dn::create($this->organization);
    }

    public function update($data) {}

    public function users()
    {
        return new UsersInterface($this->organization);
    }

    public function groups()
    {
        return new GroupsInterface;
    }

    public function destroy()
    {
        if ($org = Entry::find($this->organization_id)) {
            $org->delete($recursive = true);
        }
    }
}
