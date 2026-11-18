<?php

namespace App\Ldap;

use App\Services\Email\MailAddressRegistry;
use LdapRecord\Models\OpenLDAP\Entry;

class LdapMailAddressRegistry implements MailAddressRegistry
{
    public function inUse(string $address): bool
    {
        // Any entry in the whole directory, not one organization's: users, mailboxes and
        // user-linked mailboxes (orgMail) all count. An attribute the schema doesn't
        // define simply matches nothing.
        return Entry::query()
            ->where('mail', '=', $address)
            ->orWhere('orgMail', '=', $address)
            ->first() !== null;
    }
}
