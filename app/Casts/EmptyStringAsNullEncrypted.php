<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;

/**
 * Same behavior as the built-in 'encrypted' cast, except an empty string is
 * treated as null in both directions instead of being passed to the
 * encrypter/decrypter.
 *
 * The `servers.api_key`/`api_secret` columns are NOT NULL with no default,
 * so any row created without explicitly setting them (e.g. Servers::store(),
 * which only sets name/type/interface/status) gets '' from MySQL rather than
 * NULL. Laravel's own 'encrypted' cast throws DecryptException the moment
 * that '' is read back, since it isn't valid ciphertext - which made every
 * freshly-created Server unusable until credentials were saved at least
 * once. This cast makes '' and "not set yet" the same thing on read, and
 * keeps writing '' (not NULL) so it stays compatible with the NOT NULL
 * columns without needing a migration.
 */
class EmptyStringAsNullEncrypted implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::decryptString($value);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return '';
        }

        return Crypt::encryptString($value);
    }
}
