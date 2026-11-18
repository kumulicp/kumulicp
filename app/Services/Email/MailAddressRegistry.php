<?php

namespace App\Services\Email;

/**
 * Knows every mail address that is already taken on this server, whoever owns it.
 *
 * Addresses are unique across all organizations (mail for one address can only go to
 * one place), so this is asked about the full address, never a part of it. It is a
 * seam: the LDAP directory answers today, and the mailbox tables will once they exist.
 */
interface MailAddressRegistry
{
    /**
     * Whether the address belongs to any mailbox, alias or user. Not case sensitive.
     */
    public function inUse(string $address): bool;
}
