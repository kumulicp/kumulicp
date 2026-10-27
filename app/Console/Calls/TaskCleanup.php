<?php

namespace App\Console\Calls;

use App\Enums\TaskStatus;
use App\Support\Facades\Action;
use App\Support\Facades\Organization;
use App\Task;

class TaskCleanup
{
    public function __invoke()
    {
        // Not the ones a RunAction is still running: complete() would race run()
        $tasks = Task::where('status', TaskStatus::IN_PROGRESS->value)
            ->notClaimed()
            ->get();

        foreach ($tasks as $task) {
            Organization::setOrganization($task->organization);
            try {
                Action::complete($task);
            } catch (\Throwable $e) {
                report($e);
                $task->error_message = $e->getMessage();
                $task->error_code = 'failed_complete';
                $task->save();
            }
        }
    }
}
