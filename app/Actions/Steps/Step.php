<?php

namespace App\Actions\Steps;

use App\Actions\ActionResult;
use App\Task;
use Illuminate\Support\Str;

/**
 * One stage of a StepAction. Stateless: a fresh instance is made for each call,
 * so anything it needs to remember between calls goes in the task's custom
 * values (return them in the result).
 */
abstract class Step
{
    /**
     * Identifies the step in task_steps; stable across deploys, so don't rename
     * the class of a step that may be running.
     */
    public static function name(): string
    {
        return Str::snake(class_basename(static::class));
    }

    /**
     * Starts the step. done() moves straight on to the next step; wait() leaves
     * the step running to be check()ed; fail() rolls the task back.
     */
    abstract public function run(Task $task): ActionResult;

    /**
     * Called each time the task is polled while the step is running.
     */
    public function check(Task $task): ActionResult
    {
        return ActionResult::done();
    }

    /**
     * Undoes the step, if it was started. Returns a note for the failure
     * message, if there is something to say. Called once, in reverse step order.
     */
    public function rollback(Task $task): ?string
    {
        return null;
    }

    /**
     * Minutes after it started within which the step must be done, or null.
     */
    public function timeoutMinutes(): ?int
    {
        return null;
    }

    public function timeoutMessage(Task $task): string
    {
        return __('messages.action.step_timed_out', ['step' => static::name()]);
    }
}
