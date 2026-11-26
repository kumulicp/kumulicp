<?php

namespace App\Integrations\AccountManagers\Ldap;

use App\Contracts\AccountManager\AccountContract;
use App\Ldap\Actions\Dn;
use App\Organization;
use App\SuborgUser;
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
        return new Groups($this->organization);
    }

    public function destroy()
    {
        // A suborganization has no `o=` entry of its own: Dn::create() resolves it to its parent's, which holds
        // the parent's users, apps and groups and must never be deleted along with a suborganization.
        if ($this->organization->parent_organization_id) {
            $this->destroySuborganization();

            return;
        }

        if ($org = Entry::find($this->organization_id)) {
            $org->delete($recursive = true);
        }
    }

    /**
     * Remove only what belongs to the suborganization: its groups and its users' directory entries. The
     * apps' groups are removed with the apps, before the organization is.
     */
    private function destroySuborganization(): void
    {
        Entry::find(Dn::create($this->organization, [$this->organization->slug, 'suborgs']))?->delete($recursive = true);

        foreach (SuborgUser::where('organization_id', $this->organization->id)->get() as $suborg_user) {
            Entry::find(Dn::create($this->organization, 'users', $suborg_user->username))?->delete();
            $suborg_user->delete();
        }
    }
}
