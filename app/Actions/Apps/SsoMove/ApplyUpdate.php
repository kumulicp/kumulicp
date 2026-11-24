<?php

namespace App\Actions\Apps\SsoMove;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationSsoMove;
use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Steps\Step;
use App\Enums\TaskStatus;
use App\Support\Facades\Action as ActionFacade;
use App\Task;
use Illuminate\Support\Facades\Log;

/**
 * The new server has the app, so run the standard update: the app is given the new server's
 * address and the new client secret (they are read when the chart is made, which is why this
 * comes after the setup). Done once the app has come back up.
 *
 * A stale update from before the move would have been made for the old server.
 */
class ApplyUpdate extends Step
{
    public function run(Task $task): ActionResult
    {
        $app_instance = $task->app_instance;

        ActionFacade::clear('application_update', $app_instance);
        $update = ActionFacade::execute(new ApplicationUpdate($app_instance), $task, true);

        if (! $update) {
            return ActionResult::fail(__('messages.sso_move.update_not_scheduled'));
        }

        return ActionResult::wait(['update_task_id' => $update->id]);
    }

    public function check(Task $task): ActionResult
    {
        if (! $update_id = $task->getValue('update_task_id')) {
            return ActionResult::wait();
        }

        $update = Task::find($update_id);

        if ($update?->status === TaskStatus::COMPLETE) {
            $app_instance = $task->app_instance;
            $app_instance->status = 'active';
            $app_instance->save();

            Log::info(__('messages.sso_move.moved', [
                'app' => $app_instance->label,
                'server' => $app_instance->sso_server->server->name,
            ]), ['organization_id' => $task->organization_id]);

            return ActionResult::done();
        }

        if (! $update || $update->status === TaskStatus::FAILED) {
            return ActionResult::fail($this->failure($task));
        }

        return ActionResult::wait();
    }

    public function timeoutMinutes(): ?int
    {
        return ApplicationSsoMove::UPDATE_TIMEOUT_MINUTES;
    }

    public function timeoutMessage(Task $task): string
    {
        return $this->failure($task);
    }

    private function failure(Task $task): string
    {
        return Task::find($task->getValue('update_task_id'))?->error_message ?: __('messages.sso_move.update_failed');
    }
}
