<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing that happened during a version test run. Append-only.
 *
 * @property int $id
 * @property int $version_test_run_id
 * @property int|null $version_test_step_id
 * @property int|null $user_id
 * @property string $type
 * @property string|null $message
 * @property array|null $context
 * @property Carbon $created_at
 */
class VersionTestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'version_test_events';

    protected $casts = [
        'context' => 'array',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<VersionTestStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(VersionTestStep::class, 'version_test_step_id');
    }
}
