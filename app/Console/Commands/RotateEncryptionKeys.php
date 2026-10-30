<?php

namespace App\Console\Commands;

use App\Support\Encryption\KeyRing;
use App\Support\Encryption\KeyScanner;
use Illuminate\Console\Command;

/**
 * Re-encrypts everything still encrypted with a key from APP_PREVIOUS_KEYS using
 * the current APP_KEY. Idempotent and safe to resume: rows already on the
 * current key are left alone, unreadable values are reported and never changed.
 */
class RotateEncryptionKeys extends Command
{
    protected $signature = 'keys:rotate
        {--dry-run : Report what would be re-encrypted without writing anything}
        {--table= : Only process this table}
        {--chunk=500 : Rows to read per batch}';

    protected $description = 'Re-encrypt data stored under a previous APP_KEY with the current APP_KEY';

    public function handle(): int
    {
        if (! config('app.previous_keys')) {
            $this->warn('APP_PREVIOUS_KEYS is empty, so there is nothing to rotate from. Run keys:check to verify the current key.');
        }

        $dry_run = (bool) $this->option('dry-run');

        $report = (new KeyScanner(KeyRing::fromConfig(), max(1, (int) $this->option('chunk'))))
            ->scan(! $dry_run, $this->option('table') ?: null);

        $this->table(
            ['Location', 'Current key', 'Previous key', 'Rotated', 'Plaintext', 'Unreadable', 'Changed meanwhile'],
            array_map(fn (array $row) => [
                $row['location'], $row['current'], $row['previous'], $row['rotated'],
                $row['plaintext'], $row['undecryptable'], $row['conflicts'],
            ], $report)
        );

        $unreadable = array_sum(array_column($report, 'undecryptable'));
        $conflicts = array_sum(array_column($report, 'conflicts'));
        $pending = array_sum(array_column($report, 'previous'));

        if ($dry_run) {
            $this->info("Dry run: {$pending} value(s) would be re-encrypted. Nothing was written.");
        }

        if ($unreadable > 0) {
            $this->error("{$unreadable} value(s) can't be decrypted with any configured key and were left untouched. Add the key that encrypted them to APP_PREVIOUS_KEYS and run again.");
        }

        if ($conflicts > 0) {
            $this->warn("{$conflicts} row(s) changed while rotating and were skipped. Run keys:rotate again.");
        }

        if (! $dry_run && $unreadable === 0 && $conflicts === 0) {
            $this->info('Done. Run keys:check to confirm, then remove the old key from APP_PREVIOUS_KEYS.');
        }

        return $unreadable > 0 || $conflicts > 0 ? self::FAILURE : self::SUCCESS;
    }
}
