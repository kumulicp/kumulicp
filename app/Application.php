<?php

namespace App;

use App\Enums\AccessType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int|null $parent_app_id
 * @property string|null $description
 * @property array $domain_option
 * @property \App\Enums\AccessType $access_type
 * @property bool $primary_domain_allowed
 * @property bool $can_update_domain
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Organization> $organizations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\AppInstance> $instances
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Application> $children
 * @property-read \App\Application|null $parent_app
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\AppVersion> $versions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\AppPlan> $plans
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\AppRole> $roles
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\AppScreenshot> $screenshots
 */
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
        'domain_option' => 'array',
    ];

    /**
     * Determine whether the given domain type(s) are allowed for this app.
     *
     * @param  string|array  $types
     */
    public function hasDomainOption($types): bool
    {
        $allowed = $this->domain_option ?? [];

        foreach ((array) $types as $type) {
            if (in_array($type, $allowed)) {
                return true;
            }
        }

        return false;
    }

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

    public function screenshots()
    {
        return $this->hasMany('App\AppScreenshot', 'application_id')->orderBy('display_order');
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
