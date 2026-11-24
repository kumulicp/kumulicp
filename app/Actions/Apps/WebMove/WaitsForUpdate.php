<?php

namespace App\Actions\Apps\WebMove;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationUpdate;
use App\Enums\TaskStatus;
use App\Support\Facades\Action as ActionFacade;
use App\Task;

/**
 * For a step that starts a standard update of the app and is done when the update is. A stale update
 * from before the step would have been made for the app as it was.
 */
trait WaitsForUpdate
{
    /**
     * Where the task keeps the id of the update the step started.
     */
    abstract protected function updateKey(): string;

    /**
     * What the step says when its update did not run or did not finish.
     */
    abstract protected function failedMessage(): string;

    /**
     * @param  array<int, string>|null  $steps  The steps of the update, or all of them
     */
    protected function startUpdate(Task $task, ?array $steps = null): ActionResult
    {
        $app_instance = $task->app_instance;

        ActionFacade::clear('application_update', $app_instance);
        $update = ActionFacade::execute(new ApplicationUpdate($app_instance, $steps), $task, true);

        if (! $update) {
            return ActionResult::fail(__('messages.web_move.update_not_scheduled'));
        }

        return ActionResult::wait([$this->updateKey() => $update->id]);
    }

    protected function updateResult(Task $task): ActionResult
    {
        if (! $update_id = $task->getValue($this->updateKey())) {
            return ActionResult::wait();
        }

        $update = Task::find($update_id);

        if ($update?->status === TaskStatus::COMPLETE) {
            return ActionResult::done();
        }

        if (! $update || $update->status === TaskStatus::FAILED) {
            return ActionResult::fail($this->failure($task));
        }

        return ActionResult::wait();
    }

    protected function failure(Task $task): string
    {
        return Task::find($task->getValue($this->updateKey()))?->error_message ?: $this->failedMessage();
    }
}
