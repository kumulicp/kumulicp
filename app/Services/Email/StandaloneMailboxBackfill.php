<?php

namespace App\Services\Email;

use App\Mailbox;
use App\MailboxAddress;
use App\Organization;
use App\OrgDomain;
use App\Support\Facades\Domain;
use App\User;
use Illuminate\Support\Str;

/**
 * Records the standalone mailboxes that exist on a mail server but not in the `mailboxes`
 * table, which is what counts them against the plan and keeps their addresses taken.
 *
 * It only adds rows, never changes the mail server, and is safe to run again: an address that
 * is recorded already is left alone. An address it cannot record is reported, not forced:
 * one a user signs in with, or one that is recorded for another organization. A user's own
 * mailbox is recorded already and is not reported.
 * A recorded mailbox that is no longer on the server is reported too and left in place.
 */
class StandaloneMailboxBackfill
{
    /**
     * @return array<int, array{organization: string, address: string, action: string, reason: string}>
     */
    public function run(bool $apply = true, ?string $organization = null): array
    {
        $rows = [];

        Organization::query()
            ->when($organization, fn ($query) => $query->where('slug', $organization))
            ->orderBy('id')
            ->each(function (Organization $org) use (&$rows, $apply) {
                foreach ($org->domains()->emailEnabled()->get() as $domain) {
                    array_push($rows, ...$this->domain($org, $domain, $apply));
                }
            });

        return $rows;
    }

    /**
     * @return array<int, array{organization: string, address: string, action: string, reason: string}>
     */
    private function domain(Organization $organization, OrgDomain $domain, bool $apply): array
    {
        $row = fn (string $address, string $action, string $reason = '') => [
            'organization' => $organization->slug,
            'address' => $address,
            'action' => $action,
            'reason' => $reason,
        ];

        try {
            $entries = Domain::connect($domain, 'email')->emailList($domain);
        } catch (\Throwable $e) {
            report($e);

            return [$row('@'.$domain->name, 'failed', 'the mail server could not be asked: '.$e->getMessage())];
        }

        $rows = [];
        $on_server = [];

        foreach ($entries as $entry) {
            $address = Str::lower(trim((string) ($entry['email'] ?? '')));

            if ($address === '' || ! str_ends_with($address, '@'.Str::lower($domain->name))) {
                continue;
            }

            $on_server[] = $address;
            $recorded = MailboxAddress::where('address', $address)->first()?->mailbox;

            // Recorded already: nothing to do, including a user's own mailbox, which is recorded
            // when the user is. Only an address recorded for another organization needs a person
            if ($recorded) {
                if ((int) $recorded->organization_id !== (int) $organization->id) {
                    $rows[] = $row($address, 'collision', 'it is recorded for another organization');
                }

                continue;
            }

            $user = User::withTrashed()->whereRaw('lower(email) = ?', [$address])->first();

            if ($user) {
                $rows[] = $row($address, 'collision', "it is the sign in email of the user {$user->username}");

                continue;
            }

            if ($apply) {
                Mailbox::create([
                    'organization_id' => $organization->id,
                    'org_domain_id' => $domain->id,
                    'local_part' => Str::before($address, '@'),
                    'address' => $address,
                    'display_name' => $entry['name'] ?? null,
                    'status' => Mailbox::ACTIVE,
                    'activated_at' => now(),
                ]);
            }

            $rows[] = $row($address, $apply ? 'recorded' : 'would record');
        }

        // Recorded, but the server no longer has it. Left alone: removing it is a decision
        Mailbox::where('org_domain_id', $domain->id)
            ->whereNull('username')
            ->whereNotIn('address', $on_server)
            ->orderBy('id')
            ->each(function (Mailbox $mailbox) use (&$rows, $row) {
                $rows[] = $row($mailbox->address, 'missing', 'it is recorded, but the mail server does not have it');
            });

        return $rows;
    }
}
