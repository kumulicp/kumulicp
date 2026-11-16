<?php

namespace App\Services\Tickets;

use App\Ldap\Actions\Dn;
use App\Ldap\Models\Group as LdapGroup;
use App\Ldap\Models\User as LdapUser;
use App\Organization;
use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Who counts as support staff and who counts as an organization owner.
 *
 * For now staff are control panel admins (the existing `admin` gate). When
 * dedicated ticket permissions are introduced this is the one place to change.
 */
class TicketStaff
{
    /**
     * @return Collection<int, User>
     */
    public function users(): Collection
    {
        if (config('account_manager.driver') === 'ldap') {
            return $this->ldapAdmins();
        }

        return User::role('control_panel_admin')->get();
    }

    public function isStaff(User $user): bool
    {
        try {
            $manager = AccountManager::users($user->organization)->find($user->username);

            return $manager !== null && $manager->permissions()->hasControlPanelAdminAccess();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * A user who can still use the control panel.
     */
    public function isActive(?User $user): bool
    {
        return $user !== null && ! $user->trashed() && (bool) $user->is_allowed;
    }

    /**
     * The people to tell when a ticket loses its customer-side assignee: the
     * organization's primary contact and its organization admins.
     *
     * @return Collection<int, User>
     */
    public function organizationOwners(Organization $organization): Collection
    {
        $owners = collect();

        if ($organization->primary_contact) {
            $owners->push($organization->primary_contact);
        }

        try {
            foreach (AccountManager::users($organization)->orgAdmins() as $admin) {
                if ($user = User::where('username', $admin->attribute('username'))->first()) {
                    $owners->push($user);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $owners->filter(fn (User $user) => $this->isActive($user))->unique('id')->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function ldapAdmins(): Collection
    {
        try {
            $group = LdapGroup::find(Dn::create('server', 'controlPanelAccess', 'admin'));
            $usernames = collect($group?->getAttribute('member') ?? [])
                ->map(fn ($dn) => LdapUser::find($dn)?->getFirstAttribute('cn'))
                ->filter()
                ->all();

            return User::whereIn('username', $usernames)->get();
        } catch (Throwable $e) {
            report($e);

            return collect();
        }
    }
}
