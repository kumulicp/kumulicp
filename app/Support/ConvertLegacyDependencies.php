<?php

namespace App\Support;

use App\Enums\TaskStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Moves what open tasks recorded about the tasks they wait for into
 * task_dependencies. Safe to run again.
 *
 *  - custom_values.waiting_for / app_tasks: tasks a running task waits on to
 *    finish (gate "finish")
 *  - a prerequisite of type waiting_for: a task that must finish first (gate
 *    "start"); the prerequisite is removed
 *  - custom_values.parent_task_id: no longer used
 *
 * Tasks that no longer exist are skipped: gone counts as done.
 */
class ConvertLegacyDependencies
{
    /** @var array<int, string> */
    private const CUSTOM_VALUE_KEYS = ['waiting_for', 'app_tasks', 'parent_task_id'];

    public function convert(): int
    {
        $converted = 0;

        DB::table('tasks')
            ->where('status', '!=', TaskStatus::COMPLETE->value)
            ->orderBy('id')
            ->chunkById(200, function ($tasks) use (&$converted) {
                foreach ($tasks as $task) {
                    $converted += $this->convertTask($task);
                }
            });

        return $converted;
    }

    private function convertTask(object $task): int
    {
        $updates = [];
        $added = 0;

        $custom_values = json_decode((string) $task->custom_values, true);

        if (is_array($custom_values) && Arr::hasAny($custom_values, self::CUSTOM_VALUE_KEYS)) {
            $ids = array_merge(
                Arr::wrap($custom_values['waiting_for'] ?? []),
                Arr::wrap($custom_values['app_tasks'] ?? []),
            );
            $added += $this->link($task->id, $ids, 'finish');

            $updates['custom_values'] = json_encode(Arr::except($custom_values, self::CUSTOM_VALUE_KEYS));
        }

        $prerequisites = json_decode((string) $task->prerequisites, true);

        if (is_array($prerequisites) && is_array($prerequisites['prereqs'] ?? null)) {
            $kept = [];
            $ids = [];

            foreach ($prerequisites['prereqs'] as $prerequisite) {
                if (($prerequisite['type'] ?? null) === 'waiting_for') {
                    $ids[] = $prerequisite['task_id'] ?? null;
                } else {
                    $kept[] = $prerequisite;
                }
            }

            if ($ids !== []) {
                $added += $this->link($task->id, $ids, 'start');

                $updates['prerequisites'] = json_encode(['prereqs' => $kept]);
            }
        }

        if ($updates !== []) {
            DB::table('tasks')->where('id', $task->id)->update($updates);
        }

        return $added;
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private function link(int $task_id, array $ids, string $gate): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $ids = array_diff($ids, [$task_id]);

        if ($ids === []) {
            return 0;
        }

        $existing = DB::table('tasks')->whereIn('id', $ids)->pluck('id')->all();
        $added = 0;

        foreach ($existing as $depends_on) {
            $added += DB::table('task_dependencies')->insertOrIgnore([
                'task_id' => $task_id,
                'depends_on_task_id' => $depends_on,
                'gate' => $gate,
                'created_at' => now(),
            ]);
        }

        return $added;
    }
}
