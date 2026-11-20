<?php

namespace App\Console\Commands;

use App\Services\Email\StandaloneMailboxBackfill;
use Illuminate\Console\Command;

class BackfillMailboxes extends Command
{
    protected $signature = 'email:backfill-mailboxes
        {--organization= : Only this organization (its slug)}
        {--dry-run : Show what would be recorded without recording it}';

    protected $description = 'Record the standalone mailboxes that exist on the mail server but not in the panel, so they are counted against the plan and their addresses stay taken. Only adds records, never changes the mail server, and is safe to run again';

    public function handle(StandaloneMailboxBackfill $backfill): int
    {
        $rows = $backfill->run(! $this->option('dry-run'), $this->option('organization') ?: null);

        if ($rows === []) {
            $this->info('Every mailbox on the mail servers is recorded already.');

            return self::SUCCESS;
        }

        $this->table(['Organization', 'Address', 'Action', 'Why'], array_map(fn (array $row) => array_values($row), $rows));

        foreach (array_count_values(array_column($rows, 'action')) as $action => $count) {
            $this->line("$action: $count");
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing was recorded.');
        }

        // Collisions and servers that could not be asked need a person to look
        return in_array('collision', array_column($rows, 'action'), true) || in_array('failed', array_column($rows, 'action'), true)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
