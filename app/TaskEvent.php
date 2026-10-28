<?php

namespace App;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One thing that happened to a task: queued, started, retried, failed...
 * Append-only; deleted with its task.
 *
 * @property int $id
 * @property int $task_id
 * @property string $type
 * @property TaskStatus|null $from_status
 * @property TaskStatus|null $to_status
 * @property int $attempt
 * @property string|null $message
 * @property array|null $context
 * @property Carbon $created_at
 */
class TaskEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'task_events';

    protected $casts = [
        'from_status' => TaskStatus::class,
        'to_status' => TaskStatus::class,
        'context' => 'array',
    ];

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Never throws: the history is not worth breaking a task over (a task
     * that deleted itself, for one, can no longer have events).
     */
    public static function record(Task $task, string $type, ?string $message = null, array $context = [], ?TaskStatus $from = null): ?self
    {
        try {
            $event = new self;
            $event->task_id = $task->id;
            $event->type = $type;
            $event->from_status = $from;
            $event->to_status = $task->status;
            $event->attempt = (int) $task->attempts;
            $event->message = $message;
            $event->context = $context ?: null;
            $event->save();

            return $event;
        } catch (Throwable) {
            return null;
        }
    }
}
