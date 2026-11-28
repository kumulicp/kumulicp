<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $app_instance_id
 * @property int|null $parent_domain_id
 * @property string $host
 * @property string|null $status
 * @property-read Organization $organization
 * @property-read AppInstance|null $app_instance
 * @property-read OrgDomain|null $domain
 * @property-read AppInstance|null $primary_app_instance
 */
class OrgSubdomain extends Model
{
    use HasFactory;

    protected $table = 'org_subdomains';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['host'];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }

    /**
     * @return BelongsTo<AppInstance, $this>
     */
    public function app_instance(): BelongsTo
    {
        return $this->belongsTo('App\AppInstance', 'app_instance_id');
    }

    /**
     * @return BelongsTo<OrgDomain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo('App\OrgDomain', 'parent_domain_id');
    }

    /**
     * @return HasOne<AppInstance, $this>
     */
    public function primary_app_instance(): HasOne
    {
        return $this->hasOne('App\AppInstance', 'primary_domain');
    }
}
