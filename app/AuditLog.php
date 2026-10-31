<?php

namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One entry of the audit trail. Entries are append-only: changing or deleting a
 * single instance throws. Retention pruning (config audit.retention_days) removes
 * old rows with a bulk query, which does not go through model events.
 *
 * @property int $id
 * @property string $event_id
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property string $event
 * @property string $outcome
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property int|null $actor_organization_id
 * @property int|null $target_organization_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $subject_label
 * @property array|null $diff
 * @property array|null $properties
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property string|null $route
 * @property string $source
 * @property int|null $task_id
 */
class AuditLog extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries cannot be modified.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'diff' => 'array',
            'properties' => 'array',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = config('audit.retention_days');

        if (! $days) {
            return static::whereRaw('1 = 0');
        }

        return static::where('occurred_at', '<=', now()->subDays((int) $days));
    }
}
