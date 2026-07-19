<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $security_scan_id
 * @property string $severity
 * @property string $title
 * @property string|null $category
 * @property string|null $resource_type
 * @property string|null $resource_name
 * @property string|null $description
 * @property string|null $remediation
 * @property string|null $rule_id
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property-read \App\SecurityScan|null $scan
 */
class SecurityFinding extends Model
{
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function scan()
    {
        return $this->belongsTo('App\SecurityScan', 'security_scan_id');
    }

    public function scopeResolved($query)
    {
        return $query->whereNotNull('resolved_at');
    }

    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
