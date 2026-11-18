<?php

namespace App\Services\Email;

use App\Support\Facades\AccountManager;
use Illuminate\Support\Str;

/**
 * Moves the old `orgMail` addresses into mailbox records.
 *
 * It never changes what anybody signs in with on its own. An address that is the one the
 * user already signs in with is recorded and its `orgMail` value cleared (nothing for their
 * apps to notice). Any other is offered, and waits for the user to accept it from their
 * profile like any other offer; once they have, a later run clears the old value.
 */
class LegacyOrgMailMigrator
{
    public function __construct(private LegacyOrgMailSource $source, private MailboxService $mailboxes) {}

    /**
     * @return array<int, array{username: string, address: string, action: string, reason: string}>
     */
    public function run(bool $apply = false, ?string $organization = null, ?string $username = null): array
    {
        $rows = [];

        foreach ($this->source->entries($organization, $username) as $entry) {
            $rows[] = $this->migrate($entry, $apply);
        }

        return $rows;
    }

    /**
     * @param  array{username: string, org_mail: array<int, string>}  $entry
     * @return array{username: string, address: string, action: string, reason: string}
     */
    private function migrate(array $entry, bool $apply): array
    {
        $username = $entry['username'];
        $row = fn (string $action, string $reason = '') => [
            'username' => $username,
            'address' => implode(', ', $entry['org_mail']),
            'action' => $action,
            'reason' => $reason,
        ];

        if (count($entry['org_mail']) > 1) {
            return $row('skipped', 'several addresses: they need aliases, or a decision');
        }

        $address = Str::lower($entry['org_mail'][0]);
        $user = AccountManager::users()->find($username);

        if (! $user) {
            return $row('skipped', 'the user was not found');
        }

        $mailbox = $this->mailboxes->forUser($username);

        if ($mailbox) {
            if ($mailbox->address !== $address) {
                return $row('skipped', 'already has a different mailbox');
            }

            if (! $mailbox->isActive()) {
                return $row('waiting', 'offered, waiting for the user to accept it');
            }

            if ($apply) {
                $this->source->clear($username, $entry['org_mail'][0]);
            }

            return $row($apply ? 'cleared' : 'would clear', 'accepted, the old value is no longer needed');
        }

        $parts = explode('@', $address);
        $domain = count($parts) === 2
            ? $user->organization()?->domains()->whereRaw('lower(name) = ?', [$parts[1]])->first()
            : null;

        if (! $domain) {
            return $row('skipped', "the domain is not one of the organization's");
        }

        $same = Str::lower(trim((string) $user->attribute('email'))) === $address;

        if (! $apply) {
            return $row($same ? 'would host' : 'would offer', $same ? 'it is the address they sign in with' : 'the user accepts it from their profile');
        }

        try {
            $mailbox = $this->mailboxes->offer($user, $domain, $parts[0], $user->attribute('name'));

            if ($same) {
                $this->mailboxes->accept($user, $mailbox);
                $this->source->clear($username, $entry['org_mail'][0]);

                return $row('hosted', 'it is the address they sign in with');
            }
        } catch (MailboxException $e) {
            return $row('skipped', $e->getMessage());
        }

        return $row('offered', 'the user accepts it from their profile');
    }
}
