<?php

namespace App\Services\Email;

use App\Mailbox;
use App\OrgDomain;
use App\Support\AccountManager\UserManager;
use App\Support\Email\HostedDomains;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Gives a user an organization address and switches what they sign in with to it.
 *
 * When the address is not the one the user already has, their current email moves to be
 * their recovery email (or an existing, working recovery email is kept and the current
 * one is dropped), and `mail` becomes the new address. When it is the same address (one
 * moving to be hosted here from another provider) nothing about `mail` changes and only
 * the mailbox is recorded. Either way a user gets one organization address.
 *
 * A mailbox is `pending` while only offered and `active` once the user accepted it. The
 * switch can be undone for a while afterwards (config/email.php).
 */
class MailboxService
{
    public function __construct(private RecoveryEmailService $recovery_emails, private MailAddressRegistry $registry) {}

    public function forUser(string $username): ?Mailbox
    {
        return Mailbox::where('username', $username)->orderByDesc('id')->first();
    }

    public function active(string $username): ?Mailbox
    {
        return Mailbox::where('username', $username)->where('status', Mailbox::ACTIVE)->first();
    }

    /**
     * Gives the user a mailbox, still pending: nothing about the user changes until they
     * accept it.
     *
     * @throws MailboxException
     */
    public function offer(UserManager $user, OrgDomain $domain, string $local_part, ?string $display_name = null): Mailbox
    {
        $username = (string) $user->attribute('username');

        if ($this->forUser($username)) {
            throw new MailboxException(__('organization.email.mailbox.has_one'));
        }

        $address = Str::lower($local_part.'@'.$domain->name);

        if ($this->registry->inUse($address, $username)) {
            throw new MailboxException(__('organization.email.mailbox.taken', ['email' => $address]));
        }

        try {
            return Mailbox::create([
                'organization_id' => $domain->organization_id,
                'org_domain_id' => $domain->id,
                'local_part' => Str::lower($local_part),
                'address' => $address,
                'username' => $username,
                'display_name' => $display_name,
                'status' => Mailbox::PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new MailboxException(__('organization.email.mailbox.taken', ['email' => $address]));
        }
    }

    /**
     * Whether accepting this mailbox changes the user's email, and what has to be given.
     *
     * @return array{changes_email: bool, current: string, recovery: string}  recovery is 'keep' (a working one exists), 'move' (the current email becomes it) or 'required' (one must be given)
     */
    public function plan(UserManager $user, Mailbox $mailbox): array
    {
        $username = (string) $user->attribute('username');
        $current = Str::lower(trim((string) $user->attribute('email')));

        if ($current === $mailbox->address) {
            return ['changes_email' => false, 'current' => $current, 'recovery' => 'keep'];
        }

        $existing = $this->recovery_emails->usable($username, $current);

        if ($existing !== null && strcasecmp($existing, $mailbox->address) !== 0) {
            $recovery = 'keep';
        } elseif (! HostedDomains::isHosted($current)) {
            $recovery = 'move';
        } else {
            $recovery = 'required';
        }

        return ['changes_email' => true, 'current' => $current, 'recovery' => $recovery];
    }

}
