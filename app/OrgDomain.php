<?php

namespace App;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrgDomain extends Model
{
    use HasFactory;

    protected $table = 'org_domains';

    private $tld;

    public function organization()
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
                || $this->organization?->parent_organization_id === $organization->parent_domain_id;
    }

    private function standardPrice()
    {
        $standard_price = $this->tld->standard_price;

        if (is_float($standard_price) && $standard_price > 0) {
            return $standard_price;
        }

        return 0;
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
