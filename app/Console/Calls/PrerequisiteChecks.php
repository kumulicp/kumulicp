<?php

namespace App\Console\Calls;

use App\Support\Facades\Action;
use App\Task;

class PrerequisiteChecks
{
    public function __invoke()
    {
        $tasks = Task::where('status', 'pending')->get();

        foreach ($tasks as $task) {
            try {
                $prerequisites = Action::checkPrerequisites($task);
                if ($prerequisites->passed) {
                    $task->notified = 0;
                    $task->status = 'ready';
                    $task->save();
                } elseif ($prerequisites->permanent_fail == true) {
                    $task->status = 'failed';
                    $task->save();
                } else {
                    $task->error_code = null;
                    $task->error_message = $prerequisites->message;
                    $task->save();
                }
            } catch (\Throwable $e) {
                report($e);
                $task->error_code = 'exception';
                $task->error_message = $e->getMessage();
                $task->status = 'failed';
                $task->save();
            }
        }
    }
}
