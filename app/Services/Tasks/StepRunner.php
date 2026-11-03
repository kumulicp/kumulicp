<?php

namespace App\Services\Tasks;

use App\Actions\ActionResult;
use App\Actions\Steps\Step;
use App\Actions\Steps\StepAction;
use App\Enums\TaskStatus;
use App\Exceptions\Tasks\RetryableException;
use App\Exceptions\Tasks\WaitingException;
use App\Task;
use App\TaskEvent;
use App\TaskStep;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Throwable;

/**
 * Moves a StepAction's task along its steps, and rolls it back if one fails.
 */
class StepRunner
{
    // Longest a single pass over the steps may hold the task's lock for: a step
    // that launches a long operation before it returns can take a while.
    private const LOCK_SECONDS = 14400;

    /**
     * Does what the task's steps call for right now: starts the next step, or
     * checks the one that is running, and carries on to the following step for
     * as long as each finishes straight away. The scheduled poll and the run
     * can overlap, and a step started twice would be a second restore or a
     * second cut over, so only one pass at a time.
     *
     * @param  class-string<StepAction>  $action
     */
    public function advance(Task $task, string $action): ActionResult
    {
        $result = Cache::lock("task-steps:{$task->id}", self::LOCK_SECONDS)->get(
            fn () => $this->advanceLocked($task, $action)
        );

        // False: another pass has the task and is doing this
        return $result instanceof ActionResult ? $result : ActionResult::wait();
    }

    /**
     * @param  class-string<StepAction>  $action
     */
    private function advanceLocked(Task $task, string $action): ActionResult
    {
        $task->refresh();

        // A task that has failed or finished is not advanced any further
        if ($task->status !== TaskStatus::IN_PROGRESS) {
            return ActionResult::wait();
        }

        foreach ($this->rows($task, $action) as [$row, $step]) {
            if ($row->status === TaskStep::DONE) {
                continue;
            }

            try {
                $result = $this->work($task, $row, $step);
            } catch (WaitingException|RetryableException $e) {
                // Not the step's fault: it starts again when the task is retried
                $row->reset();

                throw $e;
            } catch (Throwable $e) {
                report($e);

                return $this->failStep($task, $action, $row, $e->getMessage());
            }

            if (! in_array($result->outcome, [ActionResult::DONE, ActionResult::WAIT, ActionResult::FAIL], true)) {
                throw new LogicException(get_class($step)." must return done(), wait() or fail(), not {$result->outcome}");
            }

            $this->remember($task, $result->values);

            if ($result->outcome === ActionResult::FAIL) {
                return $this->failStep($task, $action, $row, $result->reason ?? '');
            }

            if ($result->outcome === ActionResult::WAIT) {
                return ActionResult::wait();
            }

            $row->finish();
            TaskEvent::record($task, 'step_done', null, ['step' => $row->name]);
        }

        return ActionResult::done();
    }

    /**
     * Starts the step if it hasn't been; otherwise checks it, and gives up on
     * it if it is still going after its timeout.
     */
    private function work(Task $task, TaskStep $row, Step $step): ActionResult
    {
        if ($row->status === TaskStep::PENDING) {
            $row->start();
            TaskEvent::record($task, 'step_started', null, ['step' => $row->name]);

            return $step->run($task);
        }

        // Checked before the timeout: a step that finishes late still finished
        $result = $step->check($task);

        $minutes = $step->timeoutMinutes();

        if ($result->outcome === ActionResult::WAIT && $minutes && $row->started_at?->copy()->addMinutes($minutes)->isPast()) {
            return ActionResult::fail($step->timeoutMessage($task));
        }

        return $result;
    }

    /**
     * Marks the step failed and rolls the task back. The task is failed with
     * the action's message; the rollback is already done by the time the runner
     * calls compensate() on the way there, which then finds nothing left to undo.
     *
     * @param  class-string<StepAction>  $action
     */
    private function failStep(Task $task, string $action, TaskStep $row, string $reason): ActionResult
    {
        $row->fail($reason);
        TaskEvent::record($task, 'step_failed', $reason, ['step' => $row->name]);

        $report = $this->rollback($task, $action);

        return ActionResult::fail($action::failureMessage($task, $reason, $report['notes'], $report['errors']), 'step_failed');
    }

    /**
     * Undoes the steps that were started, last first. Safe to call again: a
     * step is only rolled back once, and one whose rollback throws is not tried
     * again.
     *
     * @param  class-string<StepAction>  $action
     * @return array{notes: array<int, string>, errors: array<int, string>}
     */
    public function rollback(Task $task, string $action): array
    {
        $steps = [];

        foreach ($action::steps() as $class) {
            $steps[$class::name()] = $class;
        }

        $report = ['notes' => [], 'errors' => []];

        $rows = TaskStep::where('task_id', $task->id)
            ->whereIn('status', [TaskStep::DONE, TaskStep::RUNNING, TaskStep::FAILED])
            ->orderByDesc('position')
            ->get();

        foreach ($rows as $row) {
            if (! isset($steps[$row->name])) {
                continue;
            }

            try {
                if ($note = (new $steps[$row->name])->rollback($task)) {
                    $report['notes'][] = $note;
                }

                $row->rolledBack();
            } catch (Throwable $e) {
                report($e);

                $report['errors'][] = $e->getMessage();
                $row->rolledBack($e->getMessage());
            }

            TaskEvent::record($task, 'step_rolled_back', null, ['step' => $row->name]);
        }

        return $report;
    }

    /**
     * The task's step rows paired with their steps, created on first use.
     *
     * @param  class-string<StepAction>  $action
     * @return array<int, array{0: TaskStep, 1: Step}>
     */
    private function rows(Task $task, string $action): array
    {
        $existing = TaskStep::where('task_id', $task->id)->get()->keyBy('name');
        $rows = [];

        foreach (array_values($action::steps()) as $position => $class) {
            $row = $existing[$class::name()] ?? $this->createRow($task, $position, $class);

            $rows[] = [$row, new $class];
        }

        return $rows;
    }

    /**
     * @param  class-string<Step>  $class
     */
    private function createRow(Task $task, int $position, string $class): TaskStep
    {
        $row = new TaskStep;
        $row->forceFill([
            'task_id' => $task->id,
            'position' => $position,
            'name' => $class::name(),
            'status' => TaskStep::PENDING,
        ])->save();

        return $row;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function remember(Task $task, array $values): void
    {
        if ($values === []) {
            return;
        }

        $task->custom_values = array_merge($task->customValues(), $values);
        $task->save();
    }
}
