<?php

namespace App\Actions\Apps\DatabaseMigrate;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationDatabaseMigrate;
use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Steps\Step;
use App\Enums\TaskStatus;
use App\OrgServer;
use App\Support\Facades\Action as ActionFacade;
use App\Task;
use Illuminate\Support\Facades\Log;

/**
 * The data is on the new server, so point the app at it, and run the standard
 * update so the chart gets the new database host. A stale update from before
 * the cutover would be configured for the old server. Done once the app has
 * come back up on the new database.
 *
 * Undone by PrepareMigration's rollback, which sees that the app points at the
 * new server.
 */
class CutOver extends Step
{
    public function run(Task $task): ActionResult
    {
        $app_instance = $task->app_instance;

        $app_instance->database_server()->associate(OrgServer::findOrFail($task->getValue('target_org_server_id')));
        $app_instance->save();

        ActionFacade::clear('application_update', $app_instance);
        $update = ActionFacade::execute(new ApplicationUpdate($app_instance), $task, true);

        if (! $update) {
            return ActionResult::fail(__('messages.database_migration.update_not_scheduled'));
        }

        return ActionResult::wait(['update_task_id' => $update->id]);
    }

    public function check(Task $task): ActionResult
    {
        // Cut over, but the update that applies it hasn't been scheduled yet
        if (! $update_id = $task->getValue('update_task_id')) {
            return ActionResult::wait();
        }

        $update = Task::find($update_id);

        if ($update?->status === TaskStatus::COMPLETE) {
            $app_instance = $task->app_instance;
            $app_instance->status = 'active';
            $app_instance->save();

            Log::info(__('messages.database_migration.migrated', [
                'app' => $app_instance->label,
                'server' => $app_instance->database_server->server->name,
            ]), ['organization_id' => $task->organization_id]);

            return ActionResult::done();
        }

        if (! $update || $update->status === TaskStatus::FAILED) {
            return ActionResult::fail($this->failure($task));
        }

        return ActionResult::wait();
    }

    /**
     * How long the app has, after being pointed at the new database, to come
     * back up on it before the migration is rolled back.
     */
    public function timeoutMinutes(): ?int
    {
        return ApplicationDatabaseMigrate::UPDATE_TIMEOUT_MINUTES;
    }

    public function timeoutMessage(Task $task): string
    {
        return $this->failure($task);
    }

    private function failure(Task $task): string
    {
        return Task::find($task->getValue('update_task_id'))?->error_message ?: __('messages.database_migration.update_failed');
    }
}
