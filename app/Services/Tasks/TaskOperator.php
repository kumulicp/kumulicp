<?php

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Exceptions\Tasks\TaskOperationRefused;
use App\Support\Facades\Organization;
use App\Task;
use App\TaskEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * What an admin can do to a task, and when. The one place the rules live, so the
 * task list, the task page and the bulk actions all agree; each action leaves a
 * mark in the task's history saying who did it.
 */
class TaskOperator
{
    /**
     * Runs the task again from the start. Only for one that failed, is blocked,
     * or was cancelled.
     */
    public function retry(Task $task, ?Authenticatable $by = null): void
    {
        if (! $task->status->isRetryable()) {
            throw new TaskOperationRefused(__('admin.tasks.not_retryable'));
        }

        TaskEvent::record($task, 'retried', null, $this->by($by), $task->status);

        app('actions')->retry($task);
    }

    /**
     * Stops the task, undoes what its action can, and blocks whatever waited for
     * it. Refused while a worker is running it: stopping it then would race the
     * worker, which would carry on to finish it.
     */
    public function cancel(Task $task, ?Authenticatable $by = null): void
    {
        if (! $task->status->isCancellable()) {
            throw new TaskOperationRefused(__('admin.tasks.not_cancellable'));
        }

        $from = $task->status;
        $cancellable = array_values(array_filter(TaskStatus::cases(), fn (TaskStatus $status) => $status->isCancellable()));

        // One statement, so a worker that claims the task in the meantime wins
        $cancelled = Task::query()
            ->whereKey($task->id)
            ->whereIn('status', array_map(fn (TaskStatus $status) => $status->value, $cancellable))
            ->notClaimed()
            ->update([
                'status' => TaskStatus::CANCELLED->value,
                'queued_at' => null,
                'next_attempt_at' => null,
                'blocked_from' => null,
                'error_code' => 'cancelled',
                'error_message' => __('admin.tasks.cancelled_by_admin'),
            ]);

        if (! $cancelled) {
            throw new TaskOperationRefused(__('admin.tasks.running_now'));
        }

        $task->refresh();

        TaskEvent::record($task, 'cancelled', $this->undo($task), $this->by($by), $from);
    }

    /**
     * Removes a task that is over. Whatever waited for it is released.
     */
    public function delete(Task $task): void
    {
        if (! $task->status->isDeletable()) {
            throw new TaskOperationRefused(__('admin.tasks.not_deletable'));
        }

        $task->delete();
    }

    /**
     * Does one operation to each task, skipping the ones it isn't allowed for.
     *
     * @param  'retry'|'cancel'|'delete'  $operation
     * @param  iterable<int, Task>  $tasks
     * @return array{done: int, refused: array<int, string>} refused: task id => why
     */
    public function bulk(string $operation, iterable $tasks, ?Authenticatable $by = null): array
    {
        $result = ['done' => 0, 'refused' => []];

        foreach ($tasks as $task) {
            try {
                match ($operation) {
                    'retry' => $this->retry($task, $by),
                    'cancel' => $this->cancel($task, $by),
                    'delete' => $this->delete($task),
                };

                $result['done']++;
            } catch (TaskOperationRefused $e) {
                $result['refused'][$task->id] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Rolls back and tidies up after a cancelled task. Whatever goes wrong is
     * reported in the note rather than stopping the cancel.
     */
    private function undo(Task $task): ?string
    {
        $notes = [];
        $handler = app('actions')->handlerFor($task);

        if (! $handler) {
            return null;
        }

        try {
            Organization::setOrganization($task->organization);
            $handler->compensate($task);
        } catch (Throwable $e) {
            report($e);
            $notes[] = 'Rolling back failed: '.$e->getMessage();
        }

        try {
            if ($note = $handler->cancelled($task)) {
                $notes[] = $note;
            }
        } catch (Throwable $e) {
            report($e);
            $notes[] = 'Tidying up failed: '.$e->getMessage();
        }

        return $notes === [] ? null : implode(' ', $notes);
    }

    /**
     * @return array<string, mixed>
     */
    private function by(?Authenticatable $by): array
    {
        return $by ? ['by' => $by->getAuthIdentifier()] : [];
    }
}
