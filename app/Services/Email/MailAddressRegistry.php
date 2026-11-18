<?php

namespace App\Services\Email;

/**
 * Knows every mail address that is already taken on this server, whoever owns it.
 *
 * Addresses are unique across all organizations (mail for one address can only go to
 * one place), so this is asked about the full address, never a part of it. It is a
 * seam: the mailbox table and, with LDAP, the directory answer together.
 */
interface MailAddressRegistry
{
    /**
     * Whether the address belongs to any mailbox, alias or user. Not case sensitive.
     *
     * The user the address is being made for can be passed, so the address they already
     * sign in with doesn't count as taken against them: someone whose `mail` is
     * me@example.com can be given a mailbox at that very address.
     */
    public function inUse(string $address, ?string $except_username = null): bool;
}
