<?php

namespace App\Console\Calls;

use App\Support\Facades\Action;
use App\Task;

class PrerequisiteChecks
{
    private $task;

    private $passed;

    private $permanent_fail;

    private $error_code;

    private $messages = [];

    public function __invoke()
    {
        $tasks = Task::where('status', 'pending')->get();

        foreach ($tasks as $task) {
            try {
                $prerequisites = Action::checkPrerequisites($task);
                if ($prerequisites->passed) {
                    $task->notified = false;
                    $task->status = 'ready';
                    $task->save();
                } elseif (! $prerequisites->passed && $prerequisites->permanent_fail == true) {
                    $task->status = 'failed';
                    $task->save();
                } else {
                    $task->error_code = $this->error_code;
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
