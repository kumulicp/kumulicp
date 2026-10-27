<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts secret values (custom plan secrets, secret feature settings) before
 * they are stored in settings JSON, and reads them back.
 */
class SecretValue
{
    public static function encrypt(string $value): string
    {
        return Crypt::encryptString($value);
    }

    // A value stored before its setting was flagged secret is still plaintext;
    // return it as is so it keeps working until it is next saved (encrypted).
    public static function decrypt(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return $stored;
        }
    }
}
