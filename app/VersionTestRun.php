<?php

namespace App;

use App\Enums\VersionTestRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One pass over every plan of an app for a version that is not enabled yet.
 *
 * @property int $id
 * @property int $application_id
 * @property int $app_version_id
 * @property int|null $baseline_version_id
 * @property VersionTestRunStatus $status
 * @property string $fingerprint
 * @property int|null $started_by_id
 * @property string|null $bypass_reason
 * @property int|null $bypassed_by_id
 * @property Carbon|null $finished_at
 * @property-read Application $application
 * @property-read AppVersion $version
 * @property-read AppVersion|null $baseline_version
 * @property-read User|null $started_by
 * @property-read User|null $bypassed_by
 */
class VersionTestRun extends Model
{
    protected $table = 'version_test_runs';

    protected $casts = [
        'status' => VersionTestRunStatus::class,
        'finished_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    /**
     * @return BelongsTo<AppVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AppVersion::class, 'app_version_id');
    }

    /**
     * @return BelongsTo<AppVersion, $this>
     */
    public function baseline_version(): BelongsTo
    {
        return $this->belongsTo(AppVersion::class, 'baseline_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function started_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function bypassed_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bypassed_by_id');
    }

    /**
     * @return HasMany<VersionTestStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(VersionTestStep::class, 'version_test_run_id')->orderBy('position');
    }

    /**
     * @return HasMany<VersionTestEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(VersionTestEvent::class, 'version_test_run_id')->orderByDesc('id');
    }

    public function isRunning(): bool
    {
        return $this->status === VersionTestRunStatus::RUNNING;
    }
}
