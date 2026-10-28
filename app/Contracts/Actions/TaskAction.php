<?php

namespace App\Contracts\Actions;

use App\Actions\Action;
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
     * A fresh action for retrying the task, or null to retry it as it is.
     */
    public static function retry(Task $task): ?Action;
}
