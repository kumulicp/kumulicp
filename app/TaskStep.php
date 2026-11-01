<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where one step of a StepAction's task has got to.
 *
 * @property int $id
 * @property int $task_id
 * @property int $position
 * @property string $name
 * @property string $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error
 */
class TaskStep extends Model
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const ROLLED_BACK = 'rolled_back';

    protected $table = 'task_steps';

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function start(): void
    {
        $this->forceFill(['status' => self::RUNNING, 'started_at' => now(), 'finished_at' => null, 'error' => null])->save();
    }

    public function finish(): void
    {
        $this->forceFill(['status' => self::DONE, 'finished_at' => now()])->save();
    }

    public function fail(string $error): void
    {
        $this->forceFill(['status' => self::FAILED, 'finished_at' => now(), 'error' => $error])->save();
    }

    /**
     * Back to not started: the step threw something that means "try again later".
     */
    public function reset(): void
    {
        $this->forceFill(['status' => self::PENDING, 'started_at' => null, 'finished_at' => null])->save();
    }

    public function rolledBack(?string $error = null): void
    {
        $this->forceFill(['status' => self::ROLLED_BACK, 'finished_at' => now(), 'error' => $error ?? $this->error])->save();
    }
}
