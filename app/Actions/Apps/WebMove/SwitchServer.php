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
 * run the standard update, which installs the release there (a release of the same name, in the
 * same namespace). The app is no longer told to stay stopped: the new release starts up with its
 * volume. The old release is still scaled to zero where it was.
 *
 * Undone by putting the chart's settings back. Pointing the app at the old server again, and
 * scaling its release up, is PrepareWebMove's rollback.
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
        $volume = AwaitVolume::confirmation($task)['values'] ?? [];

        // What was there is saved on the task as soon as it is changed, for the rollback
        if (StorageHandover::supports($app_instance) && ! empty($volume['volume'])) {
            $task->custom_values = array_merge($task->customValues(), [
                'storage_snapshot' => StorageHandover::apply($app_instance, $volume),
            ]);
            $task->save();
        }

        $app_instance->updateSetting(ApplicationWebMove::QUIESCE_SETTING, false);
        $app_instance->web_server()->associate($target);
        $app_instance->save();

        return $this->startUpdate($task);
    }

    public function check(Task $task): ActionResult
    {
        return $this->updateResult($task);
    }

    public function rollback(Task $task): ?string
    {
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
