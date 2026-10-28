<?php

namespace App\Console\Calls;

use App\Enums\TaskStatus;
use App\Support\Facades\Action;
use App\Support\Facades\Organization;
use App\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cleans up after tasks that stopped moving without anything noticing.
 */
class TaskReaper
{
    public function __invoke()
    {
        $this->requeueLostJobs();
        $this->failExpired();
    }

    /**
     * SystemTasks marks a task queued and then dispatches its job. If the job
     * is lost (worker restarted, queue flushed) nothing would ever look at the
     * task again, so put it back to ready for the next pass to dispatch. If
     * the job was only slow and runs after all, it finds the task claimed or
     * finished and does nothing.
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
            }
        }
    }

    /**
     * Actions that set a deadline_minutes are rolled back and failed once it
     * has passed, rather than being polled for ever.
     */
    private function failExpired(): void
    {
        $open = [TaskStatus::PENDING, TaskStatus::READY, TaskStatus::QUEUED, TaskStatus::IN_PROGRESS];

        $tasks = Task::whereNotNull('deadline_at')
            ->where('deadline_at', '<', now())
            ->whereIn('status', array_map(fn (TaskStatus $status) => $status->value, $open))
            ->notClaimed()
            ->get();

        foreach ($tasks as $task) {
            try {
                Organization::setOrganization($task->organization);
                Action::revert($task);
            } catch (Throwable $e) {
                report($e);
            }

            $failed = $task->transition(TaskStatus::FAILED, $open, [
                'error_code' => 'deadline_exceeded',
                'error_message' => __('messages.action.deadline_exceeded'),
            ]);

            if ($failed) {
                Log::error('Task did not finish before its deadline', [
                    'task_id' => $task->id,
                    'action' => $task->action(),
                    'organization_id' => $task->organization_id,
                ]);
            }
        }
    }
}
