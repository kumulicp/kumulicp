<?php

namespace App\Ldap\Actions;

/**
 * The one place a password is hashed for the directory.
 *
 * Salted SHA-512 crypt, stored with the `{CRYPT}` scheme prefix every directory
 * server and mail server that reads `userPassword` understands (slapd needs a system
 * crypt() that supports `$6$`, which glibc does). Hashes already in the directory are
 * left as they are: they still verify, and are replaced the next time a password is set.
 */
class PasswordHash
{
    public static function make(string $password): string
    {
        $hash = crypt($password, '$6$'.self::salt().'$');

        // crypt() reports failure as a short "*0" / "*1" string rather than throwing
        if (! str_starts_with($hash, '$6$')) {
            throw new \RuntimeException('This system cannot create a SHA-512 crypt password hash.');
        }

        return '{CRYPT}'.$hash;
    }

    /**
     * A new random salt of 16 characters from the alphabet crypt() accepts ([./A-Za-z0-9]).
     */
    private static function salt(): string
    {
        return substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16);
    }
}
