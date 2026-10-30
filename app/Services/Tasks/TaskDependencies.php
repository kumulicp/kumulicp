<?php

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Task;
use Illuminate\Support\Collection;

/**
 * Where a task stands on the tasks it waits for. A dependency that no longer
 * exists counts as done (see the task_dependencies migration).
 */
class TaskDependencies
{
    /**
     * The unfinished tasks $task waits for at $gate, split into those that have
     * failed (or are blocked themselves) and those still on their way.
     *
     * @return array{waiting: Collection<int, Task>, failed: Collection<int, Task>}
     */
    public function state(Task $task, string $gate): array
    {
        $unfinished = $task->dependencies()
            ->wherePivot('gate', $gate)
            ->where('tasks.status', '!=', TaskStatus::COMPLETE->value)
            ->get();

        [$failed, $waiting] = $unfinished->partition(
            fn (Task $dependency) => in_array($dependency->status, [TaskStatus::FAILED, TaskStatus::BLOCKED], true)
        );

        return ['waiting' => $waiting->values(), 'failed' => $failed->values()];
    }

    /**
     * The messages.action key saying why $gate holds the task back, or null if
     * it doesn't.
     */
    public function blockedBy(Task $task, string $gate): ?string
    {
        $state = $this->state($task, $gate);

        return match (true) {
            $state['failed']->isNotEmpty() => 'dependency_failed',
            $state['waiting']->isNotEmpty() => 'waiting_for',
            default => null,
        };
    }
}
