<?php

namespace App;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $status
 * @property bool $registered
 * @property string|null $expires_at
 * @property string|null $registered_at
 * @property int|null $tld_id
 * @property int|null $app_instance_id
 * @property int|null $parent_domain_id
 * @property int|null $email_server_id
 * @property int $is_primary
 * @property int $email_enabled
 * @property string|null $email_status
 * @property-read \App\Organization|null $organization
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\OrgSubdomain> $subdomains
 * @property-read \App\AppInstance|null $app_instance
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\AppInstance> $app_instances
 * @property-read \App\OrgDomain|null $parent_domain
 * @property-read \App\AppInstance|null $primary_app_instance
 * @property-read \App\Tld|null $tld
 * @property-read \App\OrgServer|null $email_server
 */
class OrgDomain extends Model
{
    use HasFactory;

    protected $table = 'org_domains';

    protected $casts = [
        'registered' => 'boolean',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Organization, $this>
     */
    public function organization(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }

    public function subdomains()
    {
        return $this->hasMany('App\OrgSubdomain', 'parent_domain_id');
    }

    public function app_instance()
    {
        return $this->belongsTo('App\AppInstance', 'app_instance_id');
    }

    public function app_instances()
    {
        return $this->hasMany('App\AppInstance', 'primary_domain_id');
    }

    public function parent_domain()
    {
        return $this->belongsTo('App\OrgDomain', 'parent_domain_id');
    }

    public function primary_app_instance()
    {
        return $this->hasOne('App\AppInstance', 'primary_domain');
    }

    public function tld()
    {
        return $this->belongsTo('App\Tld', 'tld_id');
    }

    public function sld()
    {
        $domain_parts = explode('.', $this->name);

        return $domain_parts[0];
    }

    public function email_server()
    {
        return $this->belongsTo('App\OrgServer', 'email_server_id');
    }

    public function isPrimary()
    {
        return $this->is_primary == 1;
    }

    public function isExpired()
    {
        return $this->expires_at && $this->expires_at < now();
    }

    public function expiresIn()
    {
        return $this->expires_at ? Carbon::now()->diffInYears($this->expires_at) : null;
    }

    public function belongsToOrganization(Organization $organization)
    {
        return $this->organization_id === $organization->id || $this->organization?->parent_organization_id === $organization->id;
    }

    public function belongsToOrgFamily(Organization $organization)
    {
        return $this->organization_id === $organization->id
                || $this->organization?->parent_organization_id === $organization->id
                || $this->organization?->parent_organization_id === $organization->parent_organization_id;
    }

    public function registeredAt()
    {
        return $this->registered_at ? (new Carbon($this->registered_at))->format('M d, Y') : '';
    }

    public function expiresAt()
    {
        return $this->expires_at ? (new Carbon($this->expires_at))->format('M d, Y') : '';
    }

    public function scopePrimary($query)
    {
        return $query->whereNull('parent_domain_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeEmailEnabled($query)
    {
        return $query->where('email_enabled', 1);
    }

    public function scopeEmailActive($query)
    {
        return $query->where('email_status', 'active');
    }
}
