<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $app_instance_id
 * @property int|null $parent_domain_id
 * @property string $host
 * @property string|null $status
 * @property-read \App\Organization $organization
 * @property-read \App\AppInstance|null $app_instance
 * @property-read \App\OrgDomain|null $domain
 * @property-read \App\AppInstance|null $primary_app_instance
 */
class OrgSubdomain extends Model
{
    use HasFactory;

    protected $table = 'org_subdomains';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = ['host'];

    public function organization()
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }

    public function app_instance()
    {
        return $this->belongsTo('App\AppInstance', 'app_instance_id');
    }

    public function domain()
    {
        return $this->belongsTo('App\OrgDomain', 'parent_domain_id');
    }

    public function primary_app_instance()
    {
        return $this->hasOne('App\AppInstance', 'primary_domain');
    }
}
