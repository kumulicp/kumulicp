<?php

namespace App\Services\Email;

/**
 * Where the recovery email is mirrored to when the account manager keeps one, which
 * is the attribute set for it on the LDAP settings page. The database is the source
 * of truth; this only ever copies from it.
 */
interface RecoveryEmailDirectory
{
    /**
     * Whether there is anywhere to mirror to (LDAP in use, and an attribute mapped).
     */
    public function enabled(): bool;

    /**
     * What the directory holds for an account.
     *
     * @return array{value: string|null}|null Null when the account is not in the directory
     */
    public function read(string $username): ?array;

    /**
     * Sets what the directory holds for an account (null clears it).
     *
     * @return bool False when the account is not in the directory
     */
    public function write(string $username, ?string $email): bool;

    /**
     * The usernames of every account that has a value for the attribute.
     *
     * @return array<int, string>
     */
    public function usernames(): array;
}
