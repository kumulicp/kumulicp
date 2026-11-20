<?php

namespace App\Services\Email;

use App\Events\Mailboxes\MailboxAliasesChanged;
use App\Mailbox;
use App\MailboxAddress;
use App\OrgDomain;
use App\Rules\EmailLocalPart;
use App\Support\Email\LdapEmailAttributes;
use App\Support\Facades\ServerInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * More than one address for a mailbox. An alias is a non-primary row of `mailbox_addresses`,
 * which is the source of truth and what makes the address taken. With LDAP it is also written to
 * the attribute mapped for aliases (aliases are off until an admin maps one); a directory that
 * can't be reached doesn't undo the change, and the sync puts it right later.
 */
class MailboxAliasService
{
    public function __construct(private AliasDirectory $directory, private MailAddressRegistry $registry) {}

    /**
     * @return array<int, string>
     */
    public function forMailbox(Mailbox $mailbox): array
    {
        return $mailbox->addresses()->where('is_primary', false)->orderBy('id')->pluck('address')->all();
    }

    /**
     * Whether this mailbox can have aliases: its mail server keeps them itself (Stalwart does, from the table), or
     * else the rule for LDAP, which needs an attribute mapped for them.
     */
    public function enabledFor(Mailbox $mailbox): bool
    {
        return ServerInterface::supportsAliases($mailbox->domain?->email_server) || LdapEmailAttributes::aliasesEnabled();
    }

    /**
     * @throws MailboxException
     */
    public function add(Mailbox $mailbox, string $local_part, OrgDomain $domain): MailboxAddress
    {
        if (! $this->enabledFor($mailbox)) {
            throw new MailboxException(__('organization.email.alias.disabled'));
        }

        if (! $mailbox->isActive()) {
            throw new MailboxException(__('organization.email.alias.mailbox_not_active'));
        }

        // Only a domain of the mailbox's own organization that has email on
        if ((int) $domain->organization_id !== (int) $mailbox->organization_id || ! $domain->email_enabled) {
            throw new MailboxException(__('organization.email.alias.domain_not_allowed', ['domain' => $domain->name]));
        }

        $local_part = Str::lower(trim($local_part));

        if (Validator::make(['local_part' => $local_part], ['local_part' => [new EmailLocalPart]])->fails()) {
            throw new MailboxException(__('messages.rule.email_local_part'));
        }

        $limit = config('email.max_aliases');

        if ($limit !== null && count($this->forMailbox($mailbox)) >= (int) $limit) {
            throw new MailboxException(__('organization.email.alias.limit', ['count' => (int) $limit]));
        }

        $address = $local_part.'@'.Str::lower($domain->name);

        if ($this->registry->inUse($address)) {
            throw new MailboxException(__('organization.email.mailbox.taken', ['email' => $address]));
        }

        try {
            $alias = $mailbox->addresses()->create(['address' => $address, 'is_primary' => false]);
        } catch (UniqueConstraintViolationException) {
            throw new MailboxException(__('organization.email.mailbox.taken', ['email' => $address]));
        }

        $this->changed($mailbox);

        return $alias;
    }

    /**
     * @throws MailboxException
     */
    public function remove(Mailbox $mailbox, string $address): void
    {
        $alias = $mailbox->addresses()->where('is_primary', false)->where('address', Str::lower(trim($address)))->first();

        if (! $alias) {
            throw new MailboxException(__('organization.email.alias.not_found', ['email' => $address]));
        }

        $alias->delete();

        $this->changed($mailbox);
    }

    /**
     * Drops every alias of the mailbox: it stopped being active, so nothing answers for them.
     */
    public function removeAll(Mailbox $mailbox): void
    {
        if ($mailbox->addresses()->where('is_primary', false)->delete() > 0) {
            $this->changed($mailbox);

            return;
        }

        // Nothing here, but the directory may still hold what an earlier sync or edit left
        $this->mirror($mailbox);
    }

    /**
     * Makes the directory match the database for every active mailbox that has aliases. Mailboxes
     * without any are not looked at, so an alias left in the directory for one of them is not
     * found: it only goes when the mailbox's aliases next change.
     *
     * @return array{enabled: bool, checked: int, updated: int, unchanged: int, missing: array<int, string>, failed: array<string, string>, orphans: array<int, string>}
     */
    public function sync(): array
    {
        $summary = [
            'enabled' => $this->directory->enabled(),
            'checked' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'missing' => [],
            'failed' => [],
            'orphans' => [],
        ];

        if (! $summary['enabled']) {
            return $summary;
        }

        Mailbox::query()
            ->where('status', Mailbox::ACTIVE)
            ->whereHas('addresses', fn ($query) => $query->where('is_primary', false))
            ->orderBy('id')
            ->chunkById(200, function ($mailboxes) use (&$summary) {
                foreach ($mailboxes as $mailbox) {
                    $summary['checked']++;
                    $wanted = $this->forMailbox($mailbox);

                    try {
                        $current = $this->directory->read($mailbox);

                        if ($current === null) {
                            $summary['missing'][] = $mailbox->address;
                        } elseif ($this->sameAddresses($current, $wanted)) {
                            $summary['unchanged']++;
                        } elseif ($this->directory->write($mailbox, $wanted)) {
                            $summary['updated']++;
                        } else {
                            $summary['missing'][] = $mailbox->address;
                        }
                    } catch (\Throwable $e) {
                        report($e);
                        $summary['failed'][$mailbox->address] = $e->getMessage();
                    }
                }
            });

        return $summary;
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private function sameAddresses(array $a, array $b): bool
    {
        $a = array_map('strtolower', $a);
        $b = array_map('strtolower', $b);
        sort($a);
        sort($b);

        return $a === $b;
    }

    private function changed(Mailbox $mailbox): void
    {
        $this->mirror($mailbox);

        event(new MailboxAliasesChanged($mailbox));
    }

    /**
     * Copies the mailbox's aliases to the directory. The database already holds the truth, so a
     * directory that fails is reported and left for the sync.
     */
    private function mirror(Mailbox $mailbox): void
    {
        if (! $this->directory->enabled()) {
            return;
        }

        try {
            $this->directory->write($mailbox, $this->forMailbox($mailbox));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
