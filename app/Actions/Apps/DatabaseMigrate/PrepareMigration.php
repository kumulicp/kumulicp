<?php

namespace App\Actions\Apps\DatabaseMigrate;

use App\Actions\Apps\ApplicationDatabaseMigrate;
use App\Actions\Apps\ServerMove\PrepareMove;
use App\AppInstance;
use App\OrgServer;
use App\Server;

/**
 * Checks the migration can go ahead and records where the app is coming from
 * and going to, before anything is touched (see PrepareMove, which also undoes
 * the migration: back to the database server the app was on before).
 */
class PrepareMigration extends PrepareMove
{
    protected function type(): string
    {
        return 'database';
    }

    protected function blocker(AppInstance $app_instance, Server $target_server): ?string
    {
        return ApplicationDatabaseMigrate::blocker($app_instance, $target_server, require_active: false);
    }

    protected function rollbackNote(?OrgServer $target): ?string
    {
        return $target
            ? __('messages.database_migration.copy_left', ['server' => $target->server?->name])
            : null;
    }
}
