<?php

namespace App;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * @property int $id
 * @property int|null $application_id
 * @property int|null $version_id
 * @property int|null $organization_id
 * @property int|null $app_instance_id
 * @property TaskStatus $status
 * @property string|null $error_message
 * @property int|null $job_id
 * @property string|null $action_group
 * @property string|null $action_slug
 * @property string|null $task_group
 * @property int $notified
 * @property array|null $custom_values
 * @property Carbon|null $queued_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $deadline_at
 * @property-read Application|null $application
 * @property-read AppVersion|null $version
 * @property-read Organization|null $organization
 * @property-read AppInstance|null $app_instance
 */
class Task extends Model
{
    protected $table = 'tasks';

    // Longest a RunAction may hold a task for. Past this a claim no longer
    // counts, so a worker that died mid-run can't keep the task to itself.
    public const CLAIM_LEASE_SECONDS = 1200;

    // How long a task may sit queued before the reaper assumes its job was lost
    public const QUEUED_TIMEOUT_MINUTES = 10;

    protected $casts = [
        'custom_values' => 'array',
        'status' => TaskStatus::class,
        'queued_at' => 'datetime',
        'claimed_at' => 'datetime',
        'deadline_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Flags a status change nothing is known to make. Only logged for now:
        // enforcing it waits until production shows the map is complete.
        static::saving(function (Task $task) {
            if (! $task->exists || ! $task->isDirty('status')) {
                return;
            }

            $from = $task->getOriginal('status');
            $to = $task->status;

            if ($from instanceof TaskStatus && $to instanceof TaskStatus && ! $from->canTransitionTo($to)) {
                Log::warning("Unexpected task status change {$from->value} -> {$to->value}", [
                    'task_id' => $task->id,
                    'action' => $task->action(),
                    'organization_id' => $task->organization_id,
                ]);
            }
        });
    }

    /**
     * Tasks no RunAction is currently running.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeNotClaimed(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->whereNull('claimed_at')
                ->orWhere('claimed_at', '<', now()->subSeconds(self::CLAIM_LEASE_SECONDS));
        });
    }

    /**
     * Moves the task to $to only if it is still in one of the $from statuses.
     * One UPDATE decides it, so of several processes racing to make the same
     * move exactly one wins; the others get false and must leave the task be.
     *
     * @param  TaskStatus|array<int, TaskStatus>  $from
     * @param  array<string, mixed>  $attributes  Other columns to set in the same update
     */
    public function transition(TaskStatus $to, TaskStatus|array $from, array $attributes = []): bool
    {
        $from = is_array($from) ? $from : [$from];
        $values = array_merge($attributes, ['status' => $to->value]);

        $updated = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', array_map(fn (TaskStatus $status) => $status->value, $from))
            ->update($values);

        if (! $updated) {
            return false;
        }

        $this->forceFill(array_merge($attributes, ['status' => $to]));
        $this->syncOriginalAttributes(array_merge(array_keys($attributes), ['status']));

        return true;
    }

    /**
     * Takes the task to run it, unless another RunAction already has. A task
     * can be claimed from any open status, including in_progress: some actions
     * are created in_progress and still need their run().
     */
    public function claim(): bool
    {
        $claimed_at = now();
        $open = array_map(
            fn (TaskStatus $status) => $status->value,
            [TaskStatus::PENDING, TaskStatus::READY, TaskStatus::QUEUED, TaskStatus::IN_PROGRESS],
        );

        $updated = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', $open)
            ->notClaimed()
            ->update([
                'status' => TaskStatus::IN_PROGRESS->value,
                'claimed_at' => $claimed_at,
                'queued_at' => null,
            ]);

        if (! $updated) {
            return false;
        }

        $attributes = ['status' => TaskStatus::IN_PROGRESS, 'claimed_at' => $claimed_at, 'queued_at' => null];
        $this->forceFill($attributes);
        $this->syncOriginalAttributes(array_keys($attributes));

        return true;
    }

    /**
     * Lets go of a claim. Written straight to the database so a model holding
     * stale attributes can't write them back, and without touching updated_at.
     */
    public function release(): void
    {
        $this->newQuery()->whereKey($this->getKey())->toBase()->update(['claimed_at' => null]);

        $this->forceFill(['claimed_at' => null]);
        $this->syncOriginalAttributes(['claimed_at']);
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo('App\Application', 'application_id');
    }

    /**
     * @return BelongsTo<AppVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo('App\AppVersion', 'version_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }

    /**
     * @return BelongsTo<AppInstance, $this>
     */
    public function app_instance(): BelongsTo
    {
        return $this->belongsTo('App\AppInstance', 'app_instance_id');
    }

    public function action()
    {
        $task_action_group = $this->action_group;
        $task_action_slug = $this->action_slug;
        $action = "$task_action_group.$task_action_slug";

        return $action;
    }

    public function groupNotified()
    {
        if ($this->task_group) {
            $tasks = Task::where('task_group', $this->task_group)
                ->update(['notified' => 1]);
        }
    }

    public function notified()
    {
        $this->notified = 1;
        $this->save();
    }

    public function customValues($format = 'array')
    {

        switch ($format) {
            case 'json':
                return $this->custom_values;

            case 'object':
                return (object) $this->custom_values;

            case 'array':
                return is_array($this->custom_values) ? $this->custom_values : [];

            default:
                return $this->custom_values;
        }

    }

    public function getValue($key)
    {
        return Arr::get($this->custom_values, $key, null);
    }

    public function restart()
    {
        $this->status = TaskStatus::PENDING;
        $this->job_id = 0;
        $this->save();
    }

    public function complete()
    {
        $this->status = TaskStatus::COMPLETE;
        $this->error_message = '';
        $this->save();
    }
}
