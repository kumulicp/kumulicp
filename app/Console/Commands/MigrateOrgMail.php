<?php

namespace App\Console\Commands;

use App\Services\Email\LegacyOrgMailMigrator;
use Illuminate\Console\Command;

class MigrateOrgMail extends Command
{
    protected $signature = 'email:migrate-org-mail
        {--organization= : Only this organization (its slug)}
        {--user= : Only this username}
        {--apply : Make the changes. Without it nothing is changed and the plan is only shown}';

    protected $description = 'Move the old orgMail addresses into mailbox records. Never changes what anyone signs in with: an address they already sign in with is hosted, any other is offered for them to accept';

    public function handle(LegacyOrgMailMigrator $migrator): int
    {
        if (config('account_manager.driver') !== 'ldap') {
            $this->info('Nothing to do: orgMail only ever existed in an LDAP directory.');

            return self::SUCCESS;
        }

        $rows = $migrator->run((bool) $this->option('apply'), $this->option('organization') ?: null, $this->option('user') ?: null);

        if ($rows === []) {
            $this->info('No user has an orgMail value.');

            return self::SUCCESS;
        }

        $this->table(['User', 'Address', 'Action', 'Why'], array_map(fn (array $row) => [$row['username'], $row['address'], $row['action'], $row['reason']], $rows));

        if (! $this->option('apply')) {
            $this->warn('Dry run: nothing was changed. Run again with --apply to make these changes.');
        }

        return self::SUCCESS;
    }
}
