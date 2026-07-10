<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

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
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property-read \App\OrgServer|null $org_server
 * @property-read \App\Task|null $task
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\SecurityFinding> $findings
 */
class SecurityScan extends Model
{
    protected $guarded = [];

    protected $casts = [
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function org_server()
    {
        return $this->belongsTo('App\OrgServer', 'org_server_id');
    }

    public function task()
    {
        return $this->belongsTo('App\Task', 'task_id');
    }

    public function findings()
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
