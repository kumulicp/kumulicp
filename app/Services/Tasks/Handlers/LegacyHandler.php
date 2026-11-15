<?php

namespace App\Services\Tasks\Handlers;

use App\Actions\Action;
use App\Actions\ActionResult;
use App\Task;

/**
 * Drives an action written before TaskAction existed: static run/complete/
 * retry/revert methods, any of which may be missing, that change the task
 * themselves. This is the only place that still finds them by name.
 */
class LegacyHandler implements TaskHandler
{
    /**
     * @param  class-string<Action>  $class
     */
    public function __construct(private string $class) {}

    public function actionClass(): string
    {
        return $this->class;
    }

    public function run(Task $task): ActionResult
    {
        if (! method_exists($this->class, 'run')) {
            return ActionResult::legacy();
        }

        $run = ($this->class)::run($task);

        return ActionResult::legacy($run instanceof Action ? $run : null);
    }

    public function poll(Task $task): ActionResult
    {
        if (method_exists($this->class, 'complete')) {
            // Some take the task by reference
            ($this->class)::complete($task);
        }

        return ActionResult::legacy();
    }

    public function compensate(Task $task): void
    {
        if (! method_exists($this->class, 'revert')) {
            return;
        }

        $revert = ($this->class)::revert($task);

        // Update custom values if they've been changed
        if ($revert instanceof Action && $revert->customValues() != $task->custom_values) {
            $task->custom_values = $revert->customValues();
            $task->save();
        }
    }

    public function cancelled(Task $task): ?string
    {
        // Nothing older actions know to undo beyond compensate()
        return null;
    }

    /**
     * The action's retry() gives a fresh action to take the task's values, status
     * and creation hooks from; without one the task is retried as it is.
     */
    public function reset(Task $task): void
    {
        if (! method_exists($this->class, 'retry')) {
            return;
        }

        $retry = ($this->class)::retry($task);

        if (! $retry instanceof Action) {
            return;
        }

        $task->attempts++;
        $task->error_message = '';
        $task->custom_values = $retry->custom_values;
        $task->save();

        if (method_exists($retry, 'postGenerate')) {
            $task->status = $retry->status;
            $retry->postGenerate($task);
        }

        if (method_exists($retry, 'onCreate')) {
            $retry->onCreate($task);
        }
    }
}
