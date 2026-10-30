<?php

namespace App\Actions;

use App\Task;

/**
 * What a TaskAction tells the runner about the task it just worked on. The
 * runner, not the action, moves the task to its next status.
 */
final class ActionResult
{
    public const DONE = 'done';

    public const WAIT = 'wait';

    public const RETRY_LATER = 'retry_later';

    public const FAIL = 'fail';

    // Not a result an action returns: the (legacy) action managed the task itself
    public const LEGACY = 'legacy';

    /**
     * @param  array<string, mixed>  $values  Merged into the task's custom values
     * @param  array<int, callable>  $after  Called with the task once its new state is saved
     */
    private function __construct(
        public readonly string $outcome,
        public readonly array $values = [],
        public readonly ?string $reason = null,
        public readonly ?int $seconds = null,
        public readonly ?string $code = null,
        public readonly ?Action $action = null,
        public readonly array $after = [],
        public readonly ?array $waiting_for = null,
    ) {}

    /**
     * The task is finished.
     */
    public static function done(array $values = []): self
    {
        return new self(self::DONE, $values);
    }

    /**
     * Still working: the task stays in progress and is polled again.
     */
    public static function wait(array $values = []): self
    {
        return new self(self::WAIT, $values);
    }

    /**
     * Try again later: the task goes back to pending and is not run again
     * before $seconds have passed (the action's backoff if null).
     */
    public static function retryLater(string $reason, ?int $seconds = null, array $values = []): self
    {
        return new self(self::RETRY_LATER, $values, $reason, $seconds);
    }

    /**
     * The task failed: it is rolled back and marked failed.
     */
    public static function fail(string $reason, ?string $code = null): self
    {
        return new self(self::FAIL, [], $reason, null, $code);
    }

    /**
     * Wraps what a legacy action returned (an Action carrying custom values, or
     * nothing). Whatever else the action did to the task it did itself.
     */
    public static function legacy(?Action $action = null): self
    {
        return new self(self::LEGACY, action: $action);
    }

    /**
     * Something to do once the task's new state has been saved.
     */
    public function then(callable $after): self
    {
        return new self($this->outcome, $this->values, $this->reason, $this->seconds, $this->code, $this->action, [...$this->after, $after], $this->waiting_for);
    }

    /**
     * The task can't complete until these tasks have (see Action::waitFor()).
     */
    public function waitingFor(Task ...$tasks): self
    {
        return new self($this->outcome, $this->values, $this->reason, $this->seconds, $this->code, $this->action, $this->after, array_values(array_unique([...($this->waiting_for ?? []), ...array_map(fn (Task $task) => $task->id, $tasks)])));
    }
}
