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

    public function retry(Task $task): ?Action
    {
        if (! method_exists($this->class, 'retry')) {
            return null;
        }

        $retry = ($this->class)::retry($task);

        return $retry instanceof Action ? $retry : null;
    }
}
