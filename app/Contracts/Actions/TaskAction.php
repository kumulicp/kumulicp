<?php

namespace App\Contracts\Actions;

use App\Actions\ActionResult;
use App\Task;

/**
 * The lifecycle of an action the runner drives. Actions are created with
 * Action::execute(), which saves a Task; from then on these static methods are
 * all the runner calls, with that task.
 */
interface TaskAction
{
    /**
     * Does the work, once. Throw a RetryableException/WaitingException to be run
     * again later, a PermanentFailure to fail without a retry.
     */
    public static function handle(Task $task): ActionResult;

    /**
     * Called about once a minute while the task is in progress, to see whether
     * it has finished.
     */
    public static function poll(Task $task): ActionResult;

    /**
     * Undoes what handle() did, when the task fails or misses its deadline.
     */
    public static function compensate(Task $task): void;

    /**
     * Called when an admin cancels the task, after compensate(): undo what
     * creating the task changed (an app marked "updating", say). Returns a note
     * for the task's history when something couldn't be undone, or null.
     */
    public static function cancelled(Task $task): ?string;

    /**
     * Gets the task ready to run again when it is retried. By then the runner has
     * put it back to pending and cleared its claims, steps and the tasks it waited
     * on: this is for what the action itself keeps. Drop the values a run left
     * behind (a job id, say) from $task->custom_values, and repeat what creating
     * the task did to the world (an app marked "updating") that cancelling or a
     * rollback undid. The runner saves the task afterwards. An exception fails
     * the task.
     */
    public static function reset(Task $task): void;
}
