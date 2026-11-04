<?php

namespace App;

use App\Enums\VersionTestStepStatus;
use App\Enums\VersionTestStepType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One test org for one plan: a fresh install or an upgrade, confirmed by hand.
 *
 * @property int $id
 * @property int $version_test_run_id
 * @property int $position
 * @property VersionTestStepType $type
 * @property int $app_plan_id
 * @property int|null $from_version_id
 * @property VersionTestStepStatus $status
 * @property int|null $account_test_id
 * @property int|null $task_id
 * @property int|null $app_instance_id
 * @property string|null $app_url
 * @property string|null $test_username
 * @property string|null $test_password
 * @property string|null $failure_reason
 * @property Carbon|null $started_at
 * @property Carbon|null $ready_at
 * @property Carbon|null $confirmed_at
 * @property int|null $confirmed_by_id
 * @property-read VersionTestRun $run
 * @property-read AppPlan $plan
 * @property-read AppVersion|null $from_version
 * @property-read AccountTest|null $account_test
 */
class VersionTestStep extends Model
{
    protected $table = 'version_test_steps';

    protected $casts = [
        'type' => VersionTestStepType::class,
        'status' => VersionTestStepStatus::class,
        'test_password' => 'encrypted',
        'started_at' => 'datetime',
        'ready_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<VersionTestRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(VersionTestRun::class, 'version_test_run_id');
    }

    /**
     * @return BelongsTo<AppPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(AppPlan::class, 'app_plan_id');
    }

    /**
     * @return BelongsTo<AppVersion, $this>
     */
    public function from_version(): BelongsTo
    {
        return $this->belongsTo(AppVersion::class, 'from_version_id');
    }

    /**
     * @return BelongsTo<AccountTest, $this>
     */
    public function account_test(): BelongsTo
    {
        return $this->belongsTo(AccountTest::class, 'account_test_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    /**
     * The test org this step created, if it still exists.
     */
    public function testOrganization(): ?Organization
    {
        return $this->account_test_id
            ? Organization::where('account_test_id', $this->account_test_id)->first()
            : null;
    }
}
