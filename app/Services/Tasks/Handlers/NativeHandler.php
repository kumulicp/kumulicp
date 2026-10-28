<?php

namespace App\Services\Tasks\Handlers;

use App\Actions\Action;
use App\Actions\ActionResult;
use App\Contracts\Actions\TaskAction;
use App\Task;
use LogicException;

/**
 * Drives an action that implements TaskAction.
 */
class NativeHandler implements TaskHandler
{
    /**
     * @param  class-string<TaskAction>  $class
     */
    public function __construct(private string $class) {}

    public function actionClass(): string
    {
        return $this->class;
    }

    public function run(Task $task): ActionResult
    {
        return $this->result('handle', ($this->class)::handle($task));
    }

    public function poll(Task $task): ActionResult
    {
        return $this->result('poll', ($this->class)::poll($task));
    }

    public function compensate(Task $task): void
    {
        ($this->class)::compensate($task);
    }

    public function retry(Task $task): ?Action
    {
        return ($this->class)::retry($task);
    }

    private function result(string $method, mixed $result): ActionResult
    {
        if (! $result instanceof ActionResult || $result->outcome === ActionResult::LEGACY) {
            throw new LogicException("{$this->class}::{$method}() must return an ActionResult");
        }

        return $result;
    }
}
