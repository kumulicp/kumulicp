<?php

namespace App\Actions\Apps\DatabaseMigrate;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationDatabaseMigrate;
use App\OrgServer;
use App\Task;

/**
 * A fresh backup, taken from the app's own database server. A complete copy:
 * whatever routine backups leave out would be missing from the new database.
 */
class BackupDatabase extends CopiesDatabase
{
    protected function recordKey(): string
    {
        return 'backup_id';
    }

    public function run(Task $task): ActionResult
    {
        $previous = OrgServer::findOrFail($task->getValue('previous_org_server_id'));

        $backup = ApplicationDatabaseMigrate::record($task, 'backup', $previous, ['source_org_server_id' => $previous->id, 'full_copy' => true]);
        $backup->delete_at = now()->addDays(ApplicationDatabaseMigrate::BACKUP_RETENTION_DAYS);
        $backup->save();

        // Before it is launched, so it can be found if the launch doesn't return
        ApplicationDatabaseMigrate::remember($task, ['backup_id' => $backup->id]);
        ApplicationDatabaseMigrate::launch($backup, 'run');

        // A driver that backs up before it returns is done already
        return $this->check($task);
    }
}
