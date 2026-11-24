<?php

namespace App\Actions\Apps\WebMove;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationWebMove;
use App\Actions\Steps\Step;
use App\OrgServer;
use App\Support\StorageHandover;
use App\Task;

/**
 * The volume is ready on the new cluster, so give the chart it, point the app at the new server and
 * update the release, which installs it there (a release of the same name, in the
 * same namespace). The app is no longer told to stay stopped: the new release starts up with its
 * volume. The old release is still scaled to zero where it was.
 *
 * A database server the app was copied to along with the move is given to it here, with the app
 * server (and the sign-in server, which was pointed at earlier, as the setup there needs it).
 *
 * Undone by putting the chart's settings and the database server back. Pointing the app at the old
 * app server again, and scaling its release up, is PrepareWebMove's rollback.
 */
class SwitchServer extends Step
{
    use WaitsForUpdate;

    protected function updateKey(): string
    {
        return 'update_task_id';
    }

    protected function failedMessage(): string
    {
        return __('messages.web_move.update_failed');
    }

    public function run(Task $task): ActionResult
    {
        $app_instance = $task->app_instance;
        $target = OrgServer::findOrFail($task->getValue('target_org_server_id'));
        // Chosen when the move was started, or given with the go-ahead
        $volume = AwaitVolume::chosen($task) ?? AwaitVolume::confirmation($task)['values'] ?? [];

        // What was there is saved on the task as soon as it is changed, for the rollback
        if (StorageHandover::supports($app_instance) && ! empty($volume['volume'])) {
            $task->custom_values = array_merge($task->customValues(), [
                'storage_snapshot' => StorageHandover::apply($app_instance, $volume),
            ]);
            $task->save();
        }

        $app_instance->updateSetting(ApplicationWebMove::QUIESCE_SETTING, false);
        $app_instance->web_server()->associate($target);

        // The database the app was copied to, if it was moved with the app: only now, with the app server, so
        // the old release is never given a different database to run against
        if ($database = OrgServer::find($task->getValue('database_target_org_server_id'))) {
            $app_instance->database_server()->associate($database);
        }

        $app_instance->save();

        // Only the release: it is done when the release is running there. The app's settings job comes after, once
        // that is known (RunSettingsJob): started with it, it would run against a release that is not there yet
        return $this->startUpdate($task, ['release']);
    }

    public function check(Task $task): ActionResult
    {
        return $this->updateResult($task);
    }

    public function rollback(Task $task): ?string
    {
        $database = OrgServer::find($task->getValue('database_target_org_server_id'));
        $previous = OrgServer::find($task->getValue('database_previous_org_server_id'));

        if ($database && $previous && $task->app_instance->database_server_id === $database->id) {
            $task->app_instance->database_server()->associate($previous);
            $task->app_instance->save();
        }

        $snapshot = $task->getValue('storage_snapshot');

        if (is_array($snapshot)) {
            StorageHandover::restore($task->app_instance, $snapshot);
        }

        return null;
    }

    public function timeoutMinutes(): ?int
    {
        return ApplicationWebMove::UPDATE_TIMEOUT_MINUTES;
    }

    public function timeoutMessage(Task $task): string
    {
        return $this->failure($task);
    }
}
