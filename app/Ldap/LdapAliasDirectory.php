<?php

namespace App\Ldap;

use App\Ldap\Actions\Dn;
use App\Ldap\Models\Email;
use App\Ldap\Models\User;
use App\Mailbox;
use App\Services\Email\AliasDirectory;
use App\Support\Email\LdapEmailAttributes;
use App\Support\Facades\Settings;

class LdapAliasDirectory implements AliasDirectory
{
    public function enabled(): bool
    {
        return LdapEmailAttributes::usingLdap() && LdapEmailAttributes::aliasAttribute() !== null;
    }

    public function read(Mailbox $mailbox): ?array
    {
        $entry = $this->entry($mailbox);

        return $entry ? array_values(array_map('strtolower', array_filter((array) $entry->getAttribute($this->attribute())))) : null;
    }

    public function write(Mailbox $mailbox, array $aliases): bool
    {
        $entry = $this->entry($mailbox);

        if (! $entry) {
            return false;
        }

        $entry->setAttribute($this->attribute(), array_values($aliases));
        $entry->save();

        return true;
    }

    /**
     * The entry that answers for the mailbox: the user's own, or the standalone mailbox's.
     */
    private function entry(Mailbox $mailbox): User|Email|null
    {
        if ($mailbox->username !== null) {
            // Usernames are unique across the whole directory, so there is no need to know the organization
            $user = User::where(Settings::get('ldap_username', 'cn') ?: 'cn', '=', $mailbox->username)->first();

            return $user instanceof User ? $user : null;
        }

        $organization = $mailbox->organization;

        if (! $organization) {
            return null;
        }

        $email = Email::in(Dn::create($organization, 'emails'))->where('mail', '=', $mailbox->address)->first();

        return $email instanceof Email ? $email : null;
    }

    private function attribute(): string
    {
        return (string) LdapEmailAttributes::aliasAttribute();
    }
}
