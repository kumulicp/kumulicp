<?php

namespace App\Console\Calls;

use App\Support\Facades\Action;
use App\Support\Facades\Organization;
use App\Task;

class TaskCleanup
{
    public function __invoke()
    {
        $tasks = Task::where('status', 'in_progress')
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
