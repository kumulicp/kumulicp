<?php

namespace App\Actions\Apps\WebMove;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationDatabaseMigrate;
use App\Actions\Apps\DatabaseMigrate\CopiesDatabase;
use App\Server;
use App\Services\OrgServerService;
use App\Task;

/**
 * The app is stopped, so a fresh backup of its database, taken from the database server it has,
 * is a complete copy. Only done when the move was started with a new database server for the app
 * (`database_target_server_id`); otherwise there is nothing to do.
 *
 * Nothing on the old database server is changed, and the app is not pointed at the new one yet:
 * that is SwitchServer's, together with the app server, so the old release is never given
 * anything else to run against. What the copy needs to know is saved on the task before it is
 * made, under names of its own (the app server's are `previous_org_server_id` and
 * `target_org_server_id`).
 *
 * There is nothing to undo: the backup is a copy, kept for the days a migration's is.
 */
class BackupOldDatabase extends CopiesDatabase
{
    protected function recordKey(): string
    {
        return 'database_backup_id';
    }

    public function run(Task $task): ActionResult
    {
        if (! $target_server_id = $task->getValue('database_target_server_id')) {
            return ActionResult::done();
        }

        $previous = $task->app_instance->database_server;
        $target = OrgServerService::forServer($task->organization, Server::findOrFail($target_server_id))->get();

        // Before anything is made, so the way back and the restore know them even after a crash
        ApplicationDatabaseMigrate::remember($task, [
            'database_previous_org_server_id' => $previous->id,
            'database_target_org_server_id' => $target->id,
        ]);

        $backup = ApplicationDatabaseMigrate::record($task, 'backup', $previous, ['source_org_server_id' => $previous->id, 'full_copy' => true]);
        $backup->delete_at = now()->addDays(ApplicationDatabaseMigrate::BACKUP_RETENTION_DAYS);
        $backup->save();

        // Before it is launched, so it can be found if the launch doesn't return
        ApplicationDatabaseMigrate::remember($task, ['database_backup_id' => $backup->id]);
        ApplicationDatabaseMigrate::launch($backup, 'run');

        // A driver that backs up before it returns is done already
        return $this->check($task);
    }
}
