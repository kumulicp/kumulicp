<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $security_scan_id
 * @property string $severity
 * @property string $title
 * @property string|null $category
 * @property string|null $description
 * @property string|null $remediation
 * @property string|null $rule_id
 * @property-read \App\SecurityScan|null $scan
 */
class SecurityFinding extends Model
{
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    protected $guarded = [];

    public function scan()
    {
        return $this->belongsTo('App\SecurityScan', 'security_scan_id');
    }
}
