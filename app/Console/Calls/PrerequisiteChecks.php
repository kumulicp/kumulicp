<?php

namespace App\Console\Calls;

use App\Enums\TaskStatus;
use App\Support\Facades\Action;
use App\Task;

class PrerequisiteChecks
{
    public function __invoke()
    {
        $tasks = Task::where('status', TaskStatus::PENDING->value)->get();

        foreach ($tasks as $task) {
            try {
                $prerequisites = Action::checkPrerequisites($task);
                // Moved only if still pending: a task that something else has
                // started in the meantime must not be pulled back to ready.
                if ($prerequisites->passed) {
                    $task->transition(TaskStatus::READY, TaskStatus::PENDING, ['notified' => 0]);
                } elseif ($prerequisites->permanent_fail == true) {
                    $task->transition(TaskStatus::FAILED, TaskStatus::PENDING);
                } else {
                    $task->error_code = null;
                    $task->error_message = $prerequisites->message;
                    $task->save();
                }
            } catch (\Throwable $e) {
                report($e);
                $task->transition(TaskStatus::FAILED, TaskStatus::PENDING, [
                    'error_code' => 'exception',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }
    }
}
