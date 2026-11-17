<?php

namespace App\Ldap;

use App\Ldap\Actions\Dn;
use App\Ldap\Models\User;
use App\Services\Email\LegacyOrgMailSource;
use App\Support\Facades\Settings;
use LdapRecord\LdapRecordException;

class LdapLegacyOrgMailSource implements LegacyOrgMailSource
{
    public function entries(?string $organization = null, ?string $username = null): array
    {
        $username_attribute = $this->usernameAttribute();

        try {
            $query = $organization ? User::in(Dn::create($organization, 'users')) : User::query();
            $query->whereHas('orgMail');

            if ($username !== null) {
                $query->where($username_attribute, '=', $username);
            }

            $entries = $query->get();
        } catch (LdapRecordException) {
            // A directory that never had the attribute has nothing to migrate
            return [];
        }

        return $entries
            ->map(fn (User $entry) => [
                'username' => (string) $entry->getFirstAttribute($username_attribute),
                'org_mail' => array_values($entry->getAttribute('orgmail') ?? []),
            ])
            ->filter(fn (array $row) => $row['username'] !== '' && $row['org_mail'] !== [])
            ->values()
            ->all();
    }

    public function clear(string $username, string $address): void
    {
        $entry = User::where($this->usernameAttribute(), '=', $username)->first();

        if ($entry instanceof User) {
            $entry->removeAttribute('orgMail', $address);
        }
    }

    private function usernameAttribute(): string
    {
        return Settings::get('ldap_username', 'cn') ?: 'cn';
    }
}
