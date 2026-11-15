<?php

namespace App\Actions\Steps;

use App\Actions\ActionResult;
use App\Actions\NativeAction;
use App\Services\Tasks\StepRunner;
use App\Task;

/**
 * An action that is a fixed sequence of steps. The task moves through them in
 * order, one starting when the one before is done, and is rolled back in
 * reverse if one fails. Progress is kept in task_steps.
 */
abstract class StepAction extends NativeAction
{
    /**
     * The steps, in order.
     *
     * @return array<int, class-string<Step>>
     */
    abstract public static function steps(): array;

    public static function handle(Task $task): ActionResult
    {
        return app(StepRunner::class)->advance($task, static::class);
    }

    public static function poll(Task $task): ActionResult
    {
        return app(StepRunner::class)->advance($task, static::class);
    }

    public static function compensate(Task $task): void
    {
        app(StepRunner::class)->rollback($task, static::class);
    }

    /**
     * What the task's error message says when a step fails and the rollback
     * is done.
     *
     * @param  array<int, string>  $notes  What the steps' rollbacks reported
     * @param  array<int, string>  $errors  The rollbacks that threw
     */
    public static function failureMessage(Task $task, string $reason, array $notes, array $errors): string
    {
        return trim(implode(' ', [$reason, ...$notes, ...$errors]));
    }
}
