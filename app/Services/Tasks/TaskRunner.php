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
        if ($blocked_by = $this->waitingFor($task->getValue('waiting_for'))) {
            $error_message = __("messages.action.{$blocked_by}");
            if ($task->error_message != $error_message) {
                $task->error_message = $error_message;
                $task->save();
            }

            return;
        }

        if ($handler = $this->handlerFor($task)) {
            $this->apply($task, $handler->poll($task));
        }

        if ($task->status === TaskStatus::COMPLETE) {
            $dispatcher = app(TaskDispatcher::class);

            foreach (Task::whereNot('id', $task->id)->where('task_group', $task->task_group)->get() as $other_task) {
                // Pending siblings still have to satisfy their own prerequisites
                if ($other_task->status === TaskStatus::PENDING) {
                    if (app('actions')->checkPrerequisites($other_task)->passed) {
                        $dispatcher->queue($other_task);
                    }
                } elseif ($other_task->status === TaskStatus::IN_PROGRESS) {
                    $this->poll($other_task);
                }
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

            return;
        }

        $this->mergeValues($task, $result->values);

        switch ($result->outcome) {
            case ActionResult::DONE:
                $from = $task->status;
                $task->complete();
                $task->groupNotified();
                TaskEvent::record($task, 'completed', null, [], $from);
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

    /**
     * Why the tasks this one waits on (ids) are holding it back: the
     * messages.action key to show, or null if none of them are.
     */
    private function waitingFor(?array $tasks = null): ?string
    {
        $blocked_by = null;

        foreach ($tasks ?? [] as $task) {
            $waiting_for = Task::find($task);

            if ($waiting_for && $waiting_for->status === TaskStatus::FAILED) {
                // A failed dependency won't finish by itself: say so rather
                // than showing "waiting" forever.
                return 'dependency_failed';
            }

            if ($waiting_for && $waiting_for->status !== TaskStatus::COMPLETE) {
                $blocked_by = 'waiting_for';
            }
        }

        return $blocked_by;
    }
}
