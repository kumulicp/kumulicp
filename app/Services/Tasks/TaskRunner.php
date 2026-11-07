<?php

namespace App\Services\Tasks;

use App\Actions\ActionResult;
use App\Enums\TaskStatus;
use App\Exceptions\Tasks\PermanentFailure;
use App\Exceptions\Tasks\RetryableException;
use App\Exceptions\Tasks\WaitingException;
use App\Services\Tasks\Handlers\TaskHandler;
use App\Support\Facades\Organization;
use App\Task;
use App\TaskEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs, polls and fails tasks: calls the action through its handler and turns
 * the result (or the exception) into the task's next status. Queued by
 * TaskDispatcher, run by the RunAction job.
 */
class TaskRunner
{
    // How long a task that is waiting on something else waits before trying again
    private const WAIT_SECONDS = 60;

    public function handlerFor(Task $task): ?TaskHandler
    {
        return app('actions')->handlerFor($task);
    }

    /**
     * The body of the RunAction job: takes the task, runs it, and works out
     * what its exception, if any, means for it.
     *
     * An error nothing here knows is thrown again once the action's
     * max_attempts are used up, so the queue fails the job and fail() runs.
     */
    public function run(Task $task): void
    {
        // Another run of this task may already hold it (a second dispatch, or a
        // retry racing a scheduler pass), or it may be finished already:
        // running it twice is worse than not running it.
        if (! $task->claim()) {
            Log::info('Task is already running or finished, not running it again', ['task_id' => $task->id]);

            return;
        }

        $task->attempts = $task->attempts + 1;
        $task->save();
        TaskEvent::record($task, 'started');

        try {
            Organization::setOrganization($task->organization);
            $this->execute($task);
        } catch (WaitingException $e) {
            $this->retryLater($task, $e->getMessage(), $e->retry_after ?? self::WAIT_SECONDS, 'waiting', 'waiting');
        } catch (RetryableException $e) {
            $this->retryLater($task, $e->getMessage(), $this->backoff($task), 'connection_failed', 'retry_scheduled');

            report($e);
        } catch (PermanentFailure $e) {
            $this->failTask($task, $e->getMessage(), 'permanent', $e);
        } catch (Throwable $e) {
            if ($task->attempts >= $this->maxAttempts($task)) {
                throw $e;
            }

            $this->retryLater($task, $e->getMessage(), $this->backoff($task), 'error', 'retry_scheduled');

            report($e);
        } finally {
            $task->release();
        }
    }

    /**
     * Calls the action and applies what it returned. Doesn't take the task or
     * look at its status.
     */
    public function execute(Task $task): void
    {
        $handler = $this->handlerFor($task);

        if (! $handler) {
            throw new \Exception('This action hasn\'t been registered: '.$task->action());
        }

        $this->apply($task, $handler->run($task));
    }

    /**
     * Asks the action whether the task has finished, and if it has, starts the
     * tasks that were waiting for it.
     */
    public function poll(Task $task): void
    {
        $state = app(TaskDependencies::class)->state($task, Task::GATE_FINISH);

        if ($state['failed']->isNotEmpty()) {
            $this->block($task, TaskStatus::IN_PROGRESS);

            return;
        }

        if ($state['waiting']->isNotEmpty()) {
            $error_message = __('messages.action.waiting_for');
            if ($task->error_message != $error_message) {
                $task->error_message = $error_message;
                $task->save();
            }

            return;
        }

        if ($handler = $this->handlerFor($task)) {
            $this->apply($task, $handler->poll($task));
        }
    }

    /**
     * A task needs another that has failed: park it, visibly, until that one is
     * retried or removed (TaskDispatcher::unblock() then lets it carry on).
     */
    public function block(Task $task, TaskStatus $from): void
    {
        $message = __('messages.action.dependency_failed');

        $blocked = $task->transition(TaskStatus::BLOCKED, $from, [
            'blocked_from' => $from->value,
            'error_code' => 'dependency_failed',
            'error_message' => $message,
        ]);

        if ($blocked) {
            TaskEvent::record($task, 'blocked', $message, [], $from);
        }
    }

    /**
     * A task has finished: the ones waiting for it needn't wait for the next
     * scheduled pass to carry on.
     */
    public function wakeDependents(Task $task): void
    {
        $dispatcher = app(TaskDispatcher::class);

        foreach ($task->dependents()->get() as $dependent) {
            /** @var object{gate: string} $pivot */
            $pivot = $dependent->getRelation('pivot');

            if ($pivot->gate === Task::GATE_START && in_array($dependent->status, [TaskStatus::PENDING, TaskStatus::READY], true)) {
                if (app('actions')->checkPrerequisites($dependent)->passed) {
                    $dispatcher->queue($dependent);
                }
            } elseif ($pivot->gate === Task::GATE_FINISH && $dependent->status === TaskStatus::IN_PROGRESS) {
                // Not one that is still being run: complete() would race run()
                if (Task::whereKey($dependent->id)->notClaimed()->exists()) {
                    $this->tryPoll($dependent);
                }
            }
        }
    }

    /**
     * The ids of the tasks waiting for any of $tasks, to hand to wake() once
     * those are deleted. Taken beforehand because deleting a task takes its
     * dependency rows with it: some actions delete their task (and their
     * siblings) to say they have finished, which is as good as completing.
     *
     * @param  iterable<int, Task|int>  $tasks
     * @return array<int, int>
     */
    public function dependentsOf(iterable $tasks): array
    {
        $ids = [];
        foreach ($tasks as $task) {
            $ids[] = (int) ($task instanceof Task ? $task->id : $task);
        }

        return DB::table('task_dependencies')
            ->whereIn('depends_on_task_id', $ids)
            ->pluck('task_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->diff($ids)
            ->values()
            ->all();
    }

    /**
     * The tasks that were waiting for some that have since been deleted needn't
     * wait for the next scheduled pass to find out: wakeDependents() for tasks
     * that no longer exist. Each one is polled, or started, only if what it
     * still waits for allows it.
     *
     * @param  array<int, int>  $task_ids  See dependentsOf()
     */
    public function wake(array $task_ids): void
    {
        $dispatcher = app(TaskDispatcher::class);

        foreach (Task::whereIn('id', $task_ids)->get() as $task) {
            try {
                if (in_array($task->status, [TaskStatus::PENDING, TaskStatus::READY], true)) {
                    if (app('actions')->checkPrerequisites($task)->passed) {
                        $dispatcher->queue($task);
                    }
                } elseif ($task->status === TaskStatus::IN_PROGRESS && Task::whereKey($task->id)->notClaimed()->exists()) {
                    // Not one that is still being run: complete() would race run()
                    $this->tryPoll($task);
                }
            } catch (Throwable $e) {
                // What was deleted is gone either way: the scheduled pass will pick this one up
                report($e);
            }
        }
    }

    /**
     * poll() for the scheduled pass: a task that can't be polled right now stays
     * in progress, with the reason, to be polled again next time.
     */
    public function tryPoll(Task $task): void
    {
        try {
            $this->poll($task);
        } catch (PermanentFailure $e) {
            $this->failTask($task, $e->getMessage(), 'permanent', $e);
        } catch (Throwable $e) {
            if (! $e instanceof WaitingException) {
                report($e);
            }

            $message = $e->getMessage();
            $changed = $task->error_message !== $message;

            $task->error_code = match (true) {
                $e instanceof WaitingException => 'waiting',
                $e instanceof RetryableException => 'connection_failed',
                default => 'failed_complete',
            };
            $task->error_message = $message;
            $task->save();

            // Not a row a minute for a task stuck on the same thing
            if ($changed) {
                TaskEvent::record($task, 'poll_error', $message, ['exception' => $e::class]);
            }
        }
    }

    /**
     * Undoes what the action did. Never throws.
     */
    public function compensate(Task $task): void
    {
        $this->handlerFor($task)?->compensate($task);
    }

    /**
     * The queue gave up on the job (an error run() let through, a timeout, a
     * redelivery past its attempts): roll the task back and fail it.
     */
    public function fail(Task $task, Throwable $e): void
    {
        $this->failTask($task, $e->getMessage(), 'throwable', $e);
        $task->release();
    }

    /**
     * Applies what an action returned to the task. A legacy result only carries
     * custom values: such an action sets the task's status itself.
     */
    public function apply(Task $task, ActionResult $result): void
    {
        if ($result->outcome === ActionResult::LEGACY) {
            if ($result->action && $result->action->customValues() != $task->custom_values) {
                $task->custom_values = $result->action->customValues();
                $task->save();
            }

            // Only when the action says what it waits for: one rebuilt without
            // saying keeps what it had
            if ($result->action && $result->action->finish_dependencies !== null) {
                $task->syncFinishDependencies($result->action->finish_dependencies);
            }

            // A legacy action may have completed the task itself
            if ($task->status === TaskStatus::COMPLETE) {
                $this->wakeDependents($task);
            }

            return;
        }

        $this->mergeValues($task, $result->values);

        if ($result->waiting_for !== null) {
            $task->syncFinishDependencies($result->waiting_for);
        }

        switch ($result->outcome) {
            case ActionResult::DONE:
                $from = $task->status;
                $task->complete();
                $task->groupNotified();
                TaskEvent::record($task, 'completed', null, [], $from);
                $this->wakeDependents($task);
                break;

            case ActionResult::RETRY_LATER:
                $this->retryLater($task, $result->reason, $result->seconds ?? $this->backoff($task), 'waiting', 'retry_scheduled');
                break;

            case ActionResult::FAIL:
                $this->failTask($task, $result->reason ?? '', $result->code ?? 'failed');
                break;
        }

        foreach ($result->after as $after) {
            $after($task);
        }
    }

    /**
     * Back to pending, not to run again before $seconds have passed. Only moves
     * a task that is still open: one the action has already failed stays failed.
     */
    private function retryLater(Task $task, ?string $reason, int $seconds, string $code, string $event): void
    {
        $from = $task->status;

        $moved = $task->transition(
            TaskStatus::PENDING,
            [TaskStatus::PENDING, TaskStatus::READY, TaskStatus::QUEUED, TaskStatus::IN_PROGRESS],
            [
                'job_id' => 0,
                'error_code' => $code,
                'error_message' => $reason,
                'next_attempt_at' => now()->addSeconds($seconds),
            ],
        );

        if ($moved) {
            TaskEvent::record($task, $event, $reason, ['retry_in' => $seconds], $from);
        }
    }

    /**
     * Rolls the task back and marks it failed.
     */
    public function failTask(Task $task, string $reason, string $code, ?Throwable $e = null): void
    {
        // Set before rolling back: compensate() handlers read the reason from the task
        $task->error_message = $reason;

        try {
            $this->compensate($task);
        } catch (Throwable $compensating) {
            // The task must still end up failed
            report($compensating);
        }

        $from = $task->status;

        $task->job_id = 0;
        $task->status = TaskStatus::FAILED;
        $task->error_code = $code;
        $task->error_message = $reason;
        $task->save();

        TaskEvent::record($task, 'failed', $reason, array_filter(['code' => $code, 'exception' => $e ? $e::class : null]), $from);
    }

    private function mergeValues(Task $task, array $values): void
    {
        if ($values === []) {
            return;
        }

        $merged = array_merge($task->customValues(), $values);

        if ($merged != $task->custom_values) {
            $task->custom_values = $merged;
            $task->save();
        }
    }

    /**
     * Seconds to wait before running the task again after attempt number
     * $task->attempts failed.
     */
    private function backoff(Task $task): int
    {
        $class = $this->handlerFor($task)?->actionClass();
        $steps = $class ? $class::$backoff : [];

        if ($steps === []) {
            return self::WAIT_SECONDS;
        }

        return $steps[min(max($task->attempts - 1, 0), count($steps) - 1)];
    }

    private function maxAttempts(Task $task): int
    {
        $class = $this->handlerFor($task)?->actionClass();

        return $class ? $class::$max_attempts : 1;
    }
}
