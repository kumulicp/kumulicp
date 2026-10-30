<?php

namespace App\Actions;

use App\Contracts\Actions\TaskAction;
use App\Task;

/**
 * Base for actions written for the runner. Override what the action needs: one
 * that finishes in handle() needs nothing else.
 */
abstract class NativeAction extends Action implements TaskAction
{
    public static function handle(Task $task): ActionResult
    {
        return ActionResult::done();
    }

    public static function poll(Task $task): ActionResult
    {
        return ActionResult::done();
    }

    public static function compensate(Task $task): void {}

    public static function retry(Task $task): ?Action
    {
        return null;
    }
}
