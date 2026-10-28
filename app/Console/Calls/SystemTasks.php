<?php

namespace App\Console\Calls;

use App\Enums\TaskStatus;
use App\Jobs\RunAction;
use App\Task;

class SystemTasks
{
    public function __invoke()
    {
        // Get task list
        $tasks = Task::where('status', TaskStatus::READY->value)
            ->get();

        foreach ($tasks as $task) {
            // Only the pass that wins the move to queued dispatches it
            if ($task->transition(TaskStatus::QUEUED, TaskStatus::READY, ['error_message' => null, 'queued_at' => now()])) {
                RunAction::dispatch($task);
            }
        }

        return 'success';
    }
}
