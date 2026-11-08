<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one aspect of one app instance last had applied. See App\Updates\UpdateDrift.
 *
 * @property int $id
 * @property int $app_instance_id
 * @property string $aspect
 * @property string $fingerprint
 * @property Carbon $applied_at
 * @property int|null $task_id
 * @property-read AppInstance $app_instance
 */
class UpdateState extends Model
{
    protected $table = 'update_states';

    protected $guarded = [];

    protected $casts = [
        'applied_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<AppInstance, $this>
     */
    public function app_instance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class, 'app_instance_id');
    }
}
