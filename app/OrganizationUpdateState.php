<?php

namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one aspect of one organization's subscription last had applied. The
 * counterpart of UpdateState for an organization; see App\Updates\OrganizationDrift.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $aspect
 * @property string $fingerprint What was applied
 * @property string|null $current_fingerprint What the aspect worked out the last time it was looked at
 * @property array<string, mixed>|null $details
 * @property Carbon $applied_at
 * @property Carbon|null $drift_detected_at When it was first seen to be out of date
 * @property int|null $definition_change_id The edit that put it out of date, if one was seen
 * @property Carbon|null $dismissed_at
 * @property int|null $dismissed_by_id
 * @property int $apply_attempts How many times an update was started on its own for it
 * @property Carbon|null $apply_attempted_at
 * @property int|null $task_id
 * @property-read Organization $organization
 * @property-read DefinitionChange|null $change
 */
class OrganizationUpdateState extends Model
{
    protected $table = 'organization_update_states';

    protected $guarded = [];

    protected $casts = [
        'details' => 'array',
        'applied_at' => 'datetime',
        'drift_detected_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'apply_attempted_at' => 'datetime',
    ];

    /**
     * The aspects that were out of date the last time they were looked at.
     *
     * @param  Builder<OrganizationUpdateState>  $query
     * @return Builder<OrganizationUpdateState>
     */
    public function scopeDrifted(Builder $query): Builder
    {
        return $query->whereNotNull('current_fingerprint')->whereColumn('current_fingerprint', '!=', 'fingerprint');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * @return BelongsTo<DefinitionChange, $this>
     */
    public function change(): BelongsTo
    {
        return $this->belongsTo(DefinitionChange::class, 'definition_change_id');
    }
}
