<?php

namespace App\Actions\Apps\DatabaseMigrate;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationDatabaseMigrate;
use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Steps\Step;
use App\OrgServer;
use App\Server;
use App\Services\OrgServerService;
use App\Support\Facades\Action as ActionFacade;
use App\Task;

/**
 * Checks the migration can go ahead and records where the app is coming from
 * and going to, before anything is touched, so the rollback (including one
 * after a crash) always knows the way back.
 *
 * Its rollback is the migration's: it comes last, so by then every later step
 * has been undone, and it looks at where the app actually points.
 */
class PrepareMigration extends Step
{
    public function run(Task $task): ActionResult
    {
        $app_instance = $task->app_instance;
        $target_server = Server::findOrFail($task->getValue('target_server_id'));

        if ($blocker = ApplicationDatabaseMigrate::blocker($app_instance, $target_server, require_active: false)) {
            return ActionResult::fail($blocker);
        }

        $previous = $app_instance->database_server;
        $target = OrgServerService::forServer($task->organization, $target_server)->get();

        return ActionResult::done([
            'previous_org_server_id' => $previous->id,
            'target_org_server_id' => $target->id,
        ]);
    }

    /**
     * Back to the database server the app was on before. If the app was
     * already pointed at the new server, it is updated again so the chart
     * goes back to the old database. The new server's copy is left alone: the
     * app may still be running against it until that update lands, and it is
     * only ever a copy.
     */
    public function rollback(Task $task): ?string
    {
        $app_instance = $task->app_instance;
        $previous = OrgServer::find($task->getValue('previous_org_server_id'));
        $target = OrgServer::find($task->getValue('target_org_server_id'));

        if ($previous && $app_instance->database_server_id !== $previous->id) {
            $app_instance->database_server()->associate($previous);
            $app_instance->status = 'updating';
            $app_instance->save();

            // The update that was trying to land the new database must
            // not block (or be mistaken for) the one that undoes it.
            ActionFacade::clear('application_update', $app_instance);
            ActionFacade::execute(new ApplicationUpdate($app_instance), $task, true);
        } else {
            $app_instance->status = 'active';
            $app_instance->save();
        }

        return $target
            ? __('messages.database_migration.copy_left', ['server' => $target->server?->name])
            : null;
    }
}
