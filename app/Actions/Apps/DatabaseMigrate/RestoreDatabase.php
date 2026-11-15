<?php

namespace App\Actions\Apps\DatabaseMigrate;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationDatabaseMigrate;
use App\OrgBackup;
use App\OrgServer;
use App\Task;

/**
 * Restores the backup into the new server, which adds the database and user
 * there. Nothing on the old server is modified.
 */
class RestoreDatabase extends CopiesDatabase
{
    protected function recordKey(): string
    {
        return 'restore_id';
    }

    public function run(Task $task): ActionResult
    {
        $backup = OrgBackup::findOrFail($task->getValue('backup_id'));

        if (! $backup->backup_name) {
            return ActionResult::fail(__('messages.exception.no_backup_file'));
        }

        $previous = OrgServer::findOrFail($task->getValue('previous_org_server_id'));
        $restore = ApplicationDatabaseMigrate::record($task, 'restore', $previous, ['target_org_server_id' => $task->getValue('target_org_server_id')], $backup->backup_name);

        ApplicationDatabaseMigrate::remember($task, ['restore_id' => $restore->id]);
        ApplicationDatabaseMigrate::launch($restore, 'restore');

        return $this->check($task);
    }
}
