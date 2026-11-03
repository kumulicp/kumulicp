<?php

namespace App;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One row of task_dependencies: a task waiting for another at a gate.
 *
 * @property int $task_id
 * @property int $depends_on_task_id
 * @property string $gate
 */
class TaskDependency extends Pivot
{
    protected $table = 'task_dependencies';
}
