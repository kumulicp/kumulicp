<?php

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Jobs\RunAction;
use App\Support\Facades\Action;
use App\Support\Facades\Organization;
use App\Task;
use App\TaskEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gets tasks to the queue, and keeps an eye on the ones that are on it. The
 * only place a RunAction is dispatched, so every task that is queued is also
 * one the reaper can recover.
 */
class TaskDispatcher
{
    /**
     * One scheduled pass, in the order a task moves: poll the ones running,
     * release the ones that were blocked, ready the ones whose prerequisites
     * pass, queue the ones that are ready, then clean up after the ones that
     * stalled.
     */
    public function tick(): void
    {
        $this->poll();
        $this->unblock();
        $this->promote();
        $this->queueReady();
        $this->reap();
    }

    /**
     * Queues a task to be run now. False if it can't be: finished, already
     * queued or running, or waiting out a retry delay.
     */
    public function queue(Task $task): bool
    {
        if ($task->next_attempt_at?->isFuture()) {
            return false;
        }

        $queued = $task->transition(TaskStatus::QUEUED, [TaskStatus::PENDING, TaskStatus::READY], [
            'queued_at' => now(),
            'error_message' => null,
        ]);

        if ($queued) {
            TaskEvent::record($task, 'queued');
        } elseif (! $this->createdInProgress($task)) {
            return false;
        }

        RunAction::dispatch($task);

        return true;
    }

    /**
     * Some actions are created in progress (see Task::claim()): they have no
     * status to move out of, but still have a run() waiting.
     */
    private function createdInProgress(Task $task): bool
    {
        return Task::query()
            ->whereKey($task->getKey())
            ->where('status', TaskStatus::IN_PROGRESS->value)
            ->notClaimed()
            ->exists();
    }

    /**
     * Checks the in-progress tasks nothing is running right now: has any
     * finished? (Not the ones being run, or complete() would race run().)
     */
    public function poll(): void
    {
        $tasks = Task::where('status', TaskStatus::IN_PROGRESS->value)
            ->notClaimed()
            ->get();

        foreach ($tasks as $task) {
            // Polling an earlier task may have finished this one, or started it
            $task = Task::where('status', TaskStatus::IN_PROGRESS->value)->notClaimed()->find($task->id);

            if (! $task) {
                continue;
            }

            Organization::setOrganization($task->organization);

            app(TaskRunner::class)->tryPoll($task);
        }
    }

    /**
     * Pending tasks whose prerequisites pass become ready. Those whose retry
     * delay hasn't passed yet are left alone.
     */
    public function promote(): void
    {
        $tasks = Task::where('status', TaskStatus::PENDING->value)
            ->where(function (Builder $query) {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->get();

        foreach ($tasks as $task) {
            try {
                $prerequisites = Action::checkPrerequisites($task);

                // Moved only if still pending: a task that something else has
                // started in the meantime must not be pulled back to ready.
                if ($prerequisites->blocked) {
                    $this->block($task, $prerequisites->message);
                } elseif ($prerequisites->passed) {
                    $task->transition(TaskStatus::READY, TaskStatus::PENDING, ['notified' => 0]);
                } elseif ($prerequisites->permanent_fail == true) {
                    $task->transition(TaskStatus::FAILED, TaskStatus::PENDING);
                } else {
                    $task->error_code = null;
                    $task->error_message = $prerequisites->message;
                    $task->save();
                }
            } catch (Throwable $e) {
                report($e);
                $task->transition(TaskStatus::FAILED, TaskStatus::PENDING, [
                    'error_code' => 'exception',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * A pending task whose dependency has failed waits, visibly, for that to be
     * put right.
     */
    private function block(Task $task, string $message): void
    {
        $blocked = $task->transition(TaskStatus::BLOCKED, TaskStatus::PENDING, [
            'blocked_from' => TaskStatus::PENDING->value,
            'error_code' => 'dependency_failed',
            'error_message' => $message,
        ]);

        if ($blocked) {
            TaskEvent::record($task, 'blocked', $message, [], TaskStatus::PENDING);
        }
    }

    /**
     * Blocked tasks go back to where they were once the task that failed has
     * been retried (it is no longer failed) or removed.
     */
    public function unblock(): void
    {
        $dependencies = app(TaskDependencies::class);

        foreach (Task::where('status', TaskStatus::BLOCKED->value)->get() as $task) {
            $running = $task->blocked_from === TaskStatus::IN_PROGRESS->value;
            $gate = $running ? Task::GATE_FINISH : Task::GATE_START;

            if ($dependencies->state($task, $gate)['failed']->isNotEmpty()) {
                continue;
            }

            $released = $task->transition($running ? TaskStatus::IN_PROGRESS : TaskStatus::PENDING, TaskStatus::BLOCKED, [
                'blocked_from' => null,
                'error_code' => null,
                'error_message' => null,
            ]);

            if ($released) {
                TaskEvent::record($task, 'unblocked', null, [], TaskStatus::BLOCKED);
            }
        }
    }

    /**
     * Ready tasks go on the queue. Only the pass that wins the move to queued
     * dispatches a task.
     */
    public function queueReady(): void
    {
        foreach (Task::where('status', TaskStatus::READY->value)->get() as $task) {
            $this->queue($task);
        }
    }

    /**
     * Cleans up after tasks that stopped moving without anything noticing.
     */
    public function reap(): void
    {
        $this->requeueLostJobs();
        $this->failExpired();
    }

    /**
     * A task is marked queued and then its job dispatched. If the job is lost
     * (worker restarted, queue flushed) nothing would ever look at the task
     * again, so put it back to ready for the next pass to queue. If the job was
     * only slow and runs after all, it finds the task claimed or finished and
     * does nothing.
     */
    private function requeueLostJobs(): void
    {
        $cutoff = now()->subMinutes(Task::QUEUED_TIMEOUT_MINUTES);

        // Rows queued before queued_at existed fall back to their last update
        $tasks = Task::where('status', TaskStatus::QUEUED->value)
            ->where(function (Builder $query) use ($cutoff) {
                $query->where('queued_at', '<', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff) {
                        $query->whereNull('queued_at')->where('updated_at', '<', $cutoff);
                    });
            })
            ->get();

        foreach ($tasks as $task) {
            if ($task->transition(TaskStatus::READY, TaskStatus::QUEUED, ['queued_at' => null])) {
                Log::warning('Task was queued but never started, queueing it again', [
                    'task_id' => $task->id,
                    'action' => $task->action(),
                    'organization_id' => $task->organization_id,
                ]);
                TaskEvent::record($task, 'requeued', 'The queued job never started', [], TaskStatus::QUEUED);
            }
        }
    }

    /**
     * Actions that set a deadline_minutes are rolled back and failed once it
     * has passed, rather than being polled for ever.
     */
    private function failExpired(): void
    {
        $open = [TaskStatus::PENDING, TaskStatus::READY, TaskStatus::QUEUED, TaskStatus::IN_PROGRESS, TaskStatus::BLOCKED];

        $tasks = Task::whereNotNull('deadline_at')
            ->where('deadline_at', '<', now())
            ->whereIn('status', array_map(fn (TaskStatus $status) => $status->value, $open))
            ->notClaimed()
            ->get();

        foreach ($tasks as $task) {
            try {
                Organization::setOrganization($task->organization);
                app(TaskRunner::class)->compensate($task);
            } catch (Throwable $e) {
                report($e);
            }

            $from = $task->status;
            $message = __('messages.action.deadline_exceeded');

            $failed = $task->transition(TaskStatus::FAILED, $open, [
                'error_code' => 'deadline_exceeded',
                'error_message' => $message,
            ]);

            if ($failed) {
                Log::error('Task did not finish before its deadline', [
                    'task_id' => $task->id,
                    'action' => $task->action(),
                    'organization_id' => $task->organization_id,
                ]);
                TaskEvent::record($task, 'deadline_exceeded', $message, [], $from);
            }
        }
    }
}
