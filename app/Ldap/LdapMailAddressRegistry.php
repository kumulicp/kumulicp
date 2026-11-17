<?php

namespace App\Ldap;

use App\Services\Email\MailAddressRegistry;
use App\Support\Facades\Settings;
use LdapRecord\Models\OpenLDAP\Entry;

class LdapMailAddressRegistry implements MailAddressRegistry
{
    public function inUse(string $address, ?string $except_username = null): bool
    {
        $username_attribute = Settings::get('ldap_username', 'cn') ?: 'cn';

        // Any entry in the whole directory, not one organization's: users, mailboxes and
        // user-linked mailboxes (the legacy orgMail, until it is migrated away) all count.
        // An attribute the schema doesn't define simply matches nothing.
        $entries = Entry::query()
            ->where('mail', '=', $address)
            ->orWhere('orgMail', '=', $address)
            ->get();

        foreach ($entries as $entry) {
            $owner = (string) $entry->getFirstAttribute($username_attribute);

            if ($except_username !== null && strcasecmp($owner, $except_username) === 0) {
                continue;
            }

            return true;
        }

        return false;
    }
}
