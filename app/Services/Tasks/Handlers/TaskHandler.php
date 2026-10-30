<?php

namespace App\Services\Tasks\Handlers;

use App\Actions\Action;
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

    public function retry(Task $task): ?Action;
}
