<?php

namespace App\Services\Tasks\Handlers;

use App\Actions\ActionResult;
use App\Task;

/**
 * How the runner drives one registered action class, whichever way it was written.
 */
interface TaskHandler
{
    /** The action class this handler drives */
    public function actionClass(): string;

    public function run(Task $task): ActionResult;

    public function poll(Task $task): ActionResult;

    public function compensate(Task $task): void;

    /**
     * Undoes what creating the task changed, when it is cancelled. A note for
     * the task's history if something couldn't be undone.
     */
    public function cancelled(Task $task): ?string;

    /**
     * Gets the task ready to run again, after ActionService::retry() has put it
     * back to pending.
     */
    public function reset(Task $task): void;
}
