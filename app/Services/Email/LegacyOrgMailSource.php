<?php

namespace App\Services\Email;

/**
 * Where the old `orgMail` values (an organization address kept in an attribute on the user's
 * directory entry, before mailboxes were records of their own) are read from and cleared.
 */
interface LegacyOrgMailSource
{
    /**
     * The users that still have `orgMail` values.
     *
     * @return array<int, array{username: string, org_mail: array<int, string>}>
     */
    public function entries(?string $organization = null, ?string $username = null): array;

    /**
     * Removes one `orgMail` value from a user.
     */
    public function clear(string $username, string $address): void;
}
