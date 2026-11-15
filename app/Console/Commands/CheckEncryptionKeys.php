<?php

namespace App\Console\Commands;

use App\Support\Encryption\KeyRing;
use App\Support\Encryption\KeyScanner;
use Illuminate\Console\Command;

/**
 * Read-only: confirms every encrypted value reads with the current APP_KEY
 * alone. Passing means APP_PREVIOUS_KEYS can be removed safely.
 */
class CheckEncryptionKeys extends Command
{
    protected $signature = 'keys:check
        {--table= : Only check this table}
        {--strict : Also fail on plaintext values left in always-encrypted columns}';

    protected $description = 'Verify all encrypted data can be read with the current APP_KEY alone';

    public function handle(): int
    {
        $this->line('Key fingerprint: '.substr(hash('sha256', (string) config('app.key')), 0, 8));

        $report = (new KeyScanner(KeyRing::fromConfig()))->scan(false, $this->option('table') ?: null);

        $this->table(
            ['Location', 'Current key', 'Previous key', 'Plaintext', 'Corrupt', 'Unreadable'],
            array_map(fn (array $row) => [
                $row['location'], $row['current'], $row['previous'], $row['plaintext'], $row['corrupt'], $row['undecryptable'],
            ], $report)
        );

        $previous = array_sum(array_column($report, 'previous'));
        $unreadable = array_sum(array_column($report, 'undecryptable'));
        $plaintext = array_sum(array_column($report, 'plaintext'));
        $corrupt = array_sum(array_column($report, 'corrupt'));

        if ($previous > 0) {
            $this->error("{$previous} value(s) are still encrypted with a previous key. Run keys:rotate.");
        }

        if ($unreadable > 0) {
            $this->error("{$unreadable} value(s) can't be decrypted with any configured key.");
        }

        if ($corrupt > 0) {
            $this->error("{$corrupt} value(s) look like encrypted payloads but are truncated or damaged (no key can read them). Reset the value; adding keys won't help.");
        }

        if ($plaintext > 0) {
            $this->warn("{$plaintext} value(s) in encrypted columns are stored as plaintext. Reading them through an encrypted cast throws, so encrypt them (see servers:encrypt-legacy-credentials) rather than waiting for a save.");
        }

        $failed = $previous > 0 || $unreadable > 0 || $corrupt > 0 || ($this->option('strict') && $plaintext > 0);

        if (! $failed) {
            $this->info('All encrypted data reads with the current key.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
