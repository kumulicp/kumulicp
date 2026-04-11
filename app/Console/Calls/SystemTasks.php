<?php

namespace App\Console\Calls;

use App\Jobs\RunAction;
use App\Task;

class SystemTasks
{
    public function __invoke()
    {
        // Get task list
        $tasks = Task::where('status', 'ready')
            ->get();

        foreach ($tasks as $task) {
            $task->status = 'queued';
            $task->error_message = null;
            $task->save();

            RunAction::dispatch($task);
        }

        return 'success';
    }
}
