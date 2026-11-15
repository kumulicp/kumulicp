<?php

namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one aspect of one app instance last had applied. See App\Updates\UpdateDrift.
 *
 * @property int $id
 * @property int $app_instance_id
 * @property string $aspect
 * @property string $fingerprint What was applied
 * @property string|null $current_fingerprint What the aspect worked out the last time it was looked at
 * @property Carbon $applied_at
 * @property Carbon|null $drift_detected_at When it was first seen to be out of date
 * @property int|null $definition_change_id The edit that put it out of date, if one was seen
 * @property Carbon|null $dismissed_at When an admin took it as up to date without running anything
 * @property int|null $dismissed_by_id
 * @property int|null $task_id
 * @property-read AppInstance $app_instance
 * @property-read DefinitionChange|null $change
 */
class UpdateState extends Model
{
    protected $table = 'update_states';

    protected $guarded = [];

    protected $casts = [
        'applied_at' => 'datetime',
        'drift_detected_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    /**
     * The aspects that were out of date the last time they were looked at.
     *
     * @param  Builder<UpdateState>  $query
     * @return Builder<UpdateState>
     */
    public function scopeDrifted(Builder $query): Builder
    {
        return $query->whereNotNull('current_fingerprint')->whereColumn('current_fingerprint', '!=', 'fingerprint');
    }

    /**
     * @return BelongsTo<DefinitionChange, $this>
     */
    public function change(): BelongsTo
    {
        return $this->belongsTo(DefinitionChange::class, 'definition_change_id');
    }

    /**
     * @return BelongsTo<AppInstance, $this>
     */
    public function app_instance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class, 'app_instance_id');
    }
}
