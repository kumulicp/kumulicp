<?php

namespace App;

use App\Enums\AccessType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    use HasFactory;

    protected $table = 'applications';

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'access_type' => AccessType::class,
        'primary_domain_allowed' => 'boolean',
        'can_update_domain' => 'boolean',
    ];

    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'app_instances', 'application_id', 'organization_id');
    }

    public function instances()
    {
        return $this->hasMany('App\AppInstance', 'application_id');
    }

    public function children()
    {
        return $this->hasMany('App\Application', 'parent_app_id');
    }

    public function parent_app()
    {
        return $this->belongsTo('App\Application', 'parent_app_id');
    }

    public function active_version()
    {
        return $this->versions()->where('status', 'active')->first();
    }

    public function versions()
    {
        return $this->hasMany('App\AppVersion', 'application_id');
    }

    public function plans()
    {
        return $this->hasMany('\App\AppPlan', 'application_id');
    }

    public function default_plan()
    {
        return $this->plans()->where('is_default', 1)->first();
    }

    public function get_parent_slug()
    {
        if ($this->parent_app_id) {
            return $this->parent_app->slug;
        }

        return $this->slug;
    }

    public function roles()
    {
        return $this->hasMany('App\AppRole', 'application_id');
    }

    public function is_installed(Organization $organization)
    {
        return $organization->app_instances()->where('application_id', $this->id)->count() > 0;
    }

    public function isAppInstance($organization = null)
    {
        $organization = $organization ? $organization : Organization::account();

        return $organization
            ? AppInstance::where('application_id', $this->id)->where('organization_id', $organization->id)->first()
            : null;
    }
}
