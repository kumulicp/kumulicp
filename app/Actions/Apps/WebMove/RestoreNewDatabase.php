<?php

namespace App\Actions\Apps\WebMove;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationDatabaseMigrate;
use App\Actions\Apps\DatabaseMigrate\CopiesDatabase;
use App\OrgBackup;
use App\OrgServer;
use App\Task;

/**
 * Restores the backup into the new database server, which makes the database and user there. The
 * app is not pointed at it until SwitchServer, and the old server is not modified.
 *
 * There is nothing to undo: the copy on the new server is left, the way a database migration
 * leaves its own, to be removed by hand.
 */
class RestoreNewDatabase extends CopiesDatabase
{
    protected function recordKey(): string
    {
        return 'database_restore_id';
    }

    public function run(Task $task): ActionResult
    {
        if (! $task->getValue('database_target_server_id')) {
            return ActionResult::done();
        }

        $backup = OrgBackup::findOrFail($task->getValue('database_backup_id'));

        if (! $backup->backup_name) {
            return ActionResult::fail(__('messages.exception.no_backup_file'));
        }

        $previous = OrgServer::findOrFail($task->getValue('database_previous_org_server_id'));
        $restore = ApplicationDatabaseMigrate::record($task, 'restore', $previous, ['target_org_server_id' => $task->getValue('database_target_org_server_id')], $backup->backup_name);

        ApplicationDatabaseMigrate::remember($task, ['database_restore_id' => $restore->id]);
        ApplicationDatabaseMigrate::launch($restore, 'restore');

        return $this->check($task);
    }
}
