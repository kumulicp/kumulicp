<?php

namespace App;

use App\Enums\AccessType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int|null $parent_app_id
 * @property string|null $description
 * @property array $domain_option
 * @property AccessType $access_type
 * @property bool $primary_domain_allowed
 * @property bool $can_update_domain
 * @property-read Collection<int, Organization> $organizations
 * @property-read Collection<int, AppInstance> $instances
 * @property-read Collection<int, Application> $children
 * @property-read Application|null $parent_app
 * @property-read Collection<int, AppVersion> $versions
 * @property-read Collection<int, AppPlan> $plans
 * @property-read Collection<int, AppRole> $roles
 * @property-read Collection<int, AppScreenshot> $screenshots
 */
class Application extends Model
{
    use HasFactory;

    protected $table = 'applications';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'domain_option' => '[]',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
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

    /**
     * @return HasMany<AppInstance, $this>
     */
    public function instances(): HasMany
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

    /**
     * @return HasMany<AppVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany('App\AppVersion', 'application_id');
    }

    /**
     * @return HasMany<AppPlan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany('App\AppPlan', 'application_id');
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

    /**
     * @return HasMany<AppRole, $this>
     */
    public function roles(): HasMany
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
