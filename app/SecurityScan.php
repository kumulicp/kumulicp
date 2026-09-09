<?php

namespace App;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $org_server_id
 * @property int|null $task_id
 * @property string $tool
 * @property string $status
 * @property string $triggered_by
 * @property array|null $summary
 * @property string|null $raw_output
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property-read OrgServer|null $org_server
 * @property-read Task|null $task
 * @property-read Collection<int, SecurityFinding> $findings
 */
class SecurityScan extends Model
{
    protected $guarded = [];

    protected $casts = [
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<OrgServer, $this>
     */
    public function org_server(): BelongsTo
    {
        return $this->belongsTo('App\OrgServer', 'org_server_id');
    }

    public function task()
    {
        return $this->belongsTo('App\Task', 'task_id');
    }

    /**
     * @return HasMany<SecurityFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany('App\SecurityFinding', 'security_scan_id');
    }

    public function summarize()
    {
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];

        foreach ($this->findings as $finding) {
            if (array_key_exists($finding->severity, $counts)) {
                $counts[$finding->severity]++;
            }
        }

        $this->summary = $counts;
        $this->save();

        return $counts;
    }

    public function complete()
    {
        $this->status = 'complete';
        $this->finished_at = now();
        $this->save();
    }

    public function fail(string $message)
    {
        $this->status = 'failed';
        $this->error_message = $message;
        $this->finished_at = now();
        $this->save();
    }
}
