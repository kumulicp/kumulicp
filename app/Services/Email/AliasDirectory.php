<?php

namespace App\Services\Email;

use App\Mailbox;

/**
 * Where a mailbox's aliases are mirrored to when LDAP is in use and an attribute is mapped for
 * them on the LDAP settings page. The database is the source of truth; this only copies from it.
 *
 * A mailbox that belongs to a user is that user's entry. A standalone mailbox is its own entry.
 */
interface AliasDirectory
{
    /**
     * Whether there is anywhere to mirror to (LDAP in use, and an attribute mapped).
     */
    public function enabled(): bool;

    /**
     * The aliases the directory holds for a mailbox.
     *
     * @return array<int, string>|null Null when the mailbox has no entry in the directory
     */
    public function read(Mailbox $mailbox): ?array;

    /**
     * Sets the aliases the directory holds for a mailbox (an empty list clears them).
     *
     * @param  array<int, string>  $aliases
     * @return bool False when the mailbox has no entry in the directory
     */
    public function write(Mailbox $mailbox, array $aliases): bool;
}
