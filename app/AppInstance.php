<?php

namespace App;

use App\Integrations\Applications\DemoApp\DemoAppExtensions;
use App\Integrations\Applications\Nextcloud\NextcloudExtensions;
use App\Support\Facades\Settings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $application_id
 * @property int|null $version_id
 * @property int|null $organization_id
 * @property int|null $parent_id
 * @property int|null $database_server_id
 * @property int|null $web_server_id
 * @property int|null $sso_server_id
 * @property int|null $plan_id
 * @property int|null $primary_domain_id
 * @property string $name
 * @property string $label
 * @property string|null $status
 * @property string|null $api_password
 * @property array|null $settings
 * @property Carbon|null $deactivate_at
 * @property Carbon|null $trial_ends_at
 * @property-read Application|null $application
 * @property-read AppVersion|null $version
 * @property-read Organization|null $organization
 * @property-read Collection<int, AppInstance> $children
 * @property-read AppInstance|null $parent
 * @property-read OrgServer|null $database_server
 * @property-read OrgServer|null $web_server
 * @property-read OrgServer|null $sso_server
 * @property-read Server|null $server
 * @property-read Collection<int, Task> $tasks
 * @property-read AppPlan|null $subscription
 * @property-read AppPlan|null $plan
 * @property-read Collection<int, AdditionalStorage> $additional_storage
 * @property-read Collection<int, OrgSubdomain> $domains
 * @property-read OrgSubdomain|null $primary_domain
 */
class AppInstance extends Model
{
    protected $table = 'app_instances';

    private $extensions = [
        'nextcloud' => NextcloudExtensions::class,
        'demo_app' => DemoAppExtensions::class,
    ];

    protected $casts = [
        'settings' => 'array',
        'deactivate_at' => 'date',
        'trial_ends_at' => 'date',
    ];

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo('App\Application', 'application_id');
    }

    /**
     * @return BelongsTo<AppVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo('App\AppVersion', 'version_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }

    /**
     * @return HasMany<AppInstance, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany('App\AppInstance', 'parent_id');
    }

    public function parent()
    {
        return $this->belongsTo('App\AppInstance', 'parent_id');
    }

    public function database_server()
    {
        return $this->belongsTo('App\OrgServer', 'database_server_id');
    }

    /**
     * @return BelongsTo<OrgServer, $this>
     */
    public function web_server(): BelongsTo
    {
        return $this->belongsTo('App\OrgServer', 'web_server_id');
    }

    public function sso_server()
    {
        return $this->belongsTo('App\OrgServer', 'sso_server_id');
    }

    public function server()
    {
        return $this->belongsTo('App\Server', 'app_instance_id');
    }

    public function tasks()
    {
        return $this->hasMany('App\Task', 'app_instance_id');
    }

    public function subscription()
    {
        return $this->belongsTo('App\AppPlan', 'plan_id');
    }

    public function plan()
    {
        return $this->belongsTo('App\AppPlan', 'plan_id');
    }

    public function additional_storage()
    {
        return $this->hasMany('App\AdditionalStorage', 'app_instance_id');
    }

    public function domains()
    {
        return $this->hasMany('App\OrgSubdomain', 'app_instance_id');
    }

    public function primary_domain()
    {
        return $this->belongsTo('App\OrgSubdomain', 'primary_domain_id');
    }

    public function base_domain()
    {
        return $this->organization->slug.'-'.$this->domainAlias().'.'.Settings::get('base_domain');
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

    public function appNameIncludingChildApps()
    {
        $names = [];

        foreach ($this->children as $app) {
            $names[] = $app->label;
        }

        if (count($names) > 0) {
            $namestostring = implode(', ', $names);

            return $this->label.' ('.$namestostring.')';
        }

        return $this->label;
    }

    public function address()
    {
        $http = app()->environment('production') ? 'https://' : 'http://'; // TODO: Need a setting for this cause this isn't good

        return $http.$this->domain();
    }

    // Whether a shared-plan child's site lives under its parent's domain
    // (e.g. B1Church -- every child is a subdomain of the hub) or keeps
    // whatever domain its own org admin picked on activation (e.g. ERPNext,
    // which supports any domain) is an application-level choice, not
    // something implied by server_type=shared alone -- see
    // Application::hasDomainOption() / the 'parent' domain_option, set per
    // app via the admin Applications screen.
    public function domain()
    {
        if ($this->application->hasDomainOption('parent')) {
            return $this->parent ? $this->parent->domain() : null;
        }

        if ($this->primary_domain) {
            return $this->primary_domain->name;
        }

        return $this->base_domain();
    }

    public function domainAlias()
    {
        return str_replace('_', '', $this->name);
    }

    public function getOverride($setting, $default = null)
    {
        return $this->setting('override.'.$setting, $default);
    }

    public function setOverrideIfEmpty($setting, $default)
    {
        if (! $override = $this->getOverride($setting)) {
            $this->updateSetting("override.{$setting}", $default);
        }

        return $override ?? $default;
    }

    public function feature($name)
    {
        return $this->setting("features.$name");
    }

    public function setting($setting, $default = null)
    {
        if ($this->settings != null) {
            return Arr::get($this->settings, $setting);
        }

        return $default;
    }

    public function updateSetting($setting, $value)
    {
        $settings = $this->settings ?? [];

        Arr::set($settings, $setting, $value);

        $this->settings = $settings;
        $this->save();
    }

    public function selfRegistrationEnabled(): bool
    {
        return $this->canEnableSelfRegistration() && (bool) $this->setting('self_registration_enabled');
    }

    // Distinguishes plan-level shared-app registrations (many orgs, one release —
    // AppPlan.server_type=shared + global_app_id) from type-level parent apps
    // (same org, same release, activation_type=job — routed explicitly by the
    // Actions layer instead). Server managers should reroute add/update/delete
    // to this parent instead of deploying their own release when this is set.
    public function sharedPlanParent(): ?self
    {
        return $this->parent_id && $this->plan?->setting('server_type') === 'shared'
            ? $this->parent
            : null;
    }

    public function canEnableSelfRegistration(): bool
    {
        return (bool) $this->plan?->selfRegistrationEnabled() && count($this->version?->defaultUserRoles() ?? []) > 0;
    }

    public function admin_address()
    {
        $version = $this->version;
        if ($version->admin_path) {
            return $this->address().$version->admin_path;
        }

        return $this->address();
    }

    public function api_password()
    {
        $api_password = $this->api_password;

        try {
            $api_password = Crypt::decrypt($this->api_password);
        } catch (\Throwable $e) {
            $api_password = '**Corrupted Password**';
        }

        return $api_password;
    }

    public function dbPassword(): string
    {
        try {
            if ($encrypted = $this->setting('db_password')) {
                return Crypt::decryptString($encrypted);
            }
        } catch (\Throwable $e) {
            // fall through to org secretpw for existing instances
        }

        return $this->organization->secretpw;
    }

    public function siteApiKey(): string
    {
        try {
            if ($encrypted = $this->setting('site_api_key')) {
                return Crypt::decryptString($encrypted);
            }
        } catch (\Throwable $e) {
            //
        }

        $key = Str::random(32);
        $this->updateSetting('site_api_key', Crypt::encryptString($key));

        return $key;
    }

    public function extensionExists($extension)
    {

        if (array_key_exists($this->application->slug, $this->extensions)) {

            $app_extensions = new $this->extensions[$this->application->slug]($this);

            return method_exists($app_extensions, $extension) ? true : false;

        }

        return false;

    }

    public function extension($extension, $attribute = [])
    {
        if (array_key_exists($this->application->slug, $this->extensions)) {

            $app_extensions = new $this->extensions[$this->application->slug]($this);

            if (method_exists($app_extensions, $extension)) {

                return $app_extensions->$extension($attribute);

            }

        }

        return null;
    }

    public function isServer()
    {
        return isset($this->server);
    }

    public function scopeActive(Builder $query)
    {
        return $query->where('status', 'active');
    }

    public function scopeNotDeactivated(Builder $query)
    {
        return $query->where('status', '!=', 'deactivated')->where('status', '!=', 'deactivating');
    }

    public function standard_user_name()
    {
        return $this->name.'-standard';
    }

    public function basic_user_name()
    {
        return $this->name.'-basic';
    }
}
