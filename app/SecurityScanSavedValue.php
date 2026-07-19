<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * A reusable value an admin has entered for a security scan (e.g. a custom
 * domain for Nuclei, or a custom namespace for Trivy), saved per organization
 * so it can be picked again on a future scan instead of retyped.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $type
 * @property string $value
 * @property-read \App\Organization|null $organization
 */
class SecurityScanSavedValue extends Model
{
    protected $guarded = [];

    public const TYPE_DOMAIN = 'domain';

    public const TYPE_NAMESPACE = 'namespace';

    public function organization()
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }

    public function scopeType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
