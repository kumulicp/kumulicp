<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * One-off backfill for the pre-existing plaintext Server.api_key/api_secret columns.
 *
 * Run this BEFORE deploying the code that adds the `encrypted` cast to those
 * columns on the Server model — otherwise Eloquent will try to decrypt
 * still-plaintext values and throw. Safe to run more than once: already
 * encrypted values are detected and left untouched.
 */
class EncryptLegacyServerCredentials extends Command
{
    protected $signature = 'servers:encrypt-legacy-credentials';

    protected $description = 'Encrypt any plaintext Server api_key/api_secret values still stored from before encryption was added';

    public function handle(): int
    {
        $servers = DB::table('servers')->select('id', 'api_key', 'api_secret')->get();

        $encrypted = 0;
        $skipped = 0;

        foreach ($servers as $server) {
            $update = [];

            foreach (['api_key', 'api_secret'] as $column) {
                $value = $server->$column;

                if ($value === null || $value === '') {
                    continue;
                }

                if ($this->isAlreadyEncrypted($value)) {
                    continue;
                }

                $update[$column] = Crypt::encryptString($value);
            }

            if ($update) {
                DB::table('servers')->where('id', $server->id)->update($update);
                $encrypted++;
            } else {
                $skipped++;
            }
        }

        $this->info("Encrypted credentials on {$encrypted} server(s); {$skipped} already encrypted or empty.");

        return self::SUCCESS;
    }

    private function isAlreadyEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
