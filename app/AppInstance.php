<?php

namespace App;

use App\Integrations\Applications\Nextcloud\NextcloudExtensions;
use App\Support\Facades\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;

class AppInstance extends Model
{
    protected $table = 'app_instances';

    private $extensions = [
        'nextcloud' => NextcloudExtensions::class,
    ];

    protected $casts = [
        'settings' => 'array',
        'deactivate_at' => 'date',
        'trial_ends_at' => 'date',
    ];

    public function application()
    {
        return $this->belongsTo('App\Application', 'application_id');
    }

    public function version()
    {
        return $this->belongsTo('App\AppVersion', 'version_id');
    }

    public function organization()
    {
        return $this->belongsTo('App\Organization', 'organization_id');
    }

    public function children()
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

    public function web_server()
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
                || $this->organization?->parent_organization_id === $organization->parent_domain_id;
    }

    public function appNameIncludingChildApps()
    {
        $names = [];

        foreach ($this->children() as $app) {
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
        $http = env('APP_ENV') === 'production' ? 'https://' : 'http://'; // TODO: Need a setting for this cause this isn't good

        return $http.$this->domain();
    }

    public function domain()
    {
        $shared = $this->plan->setting('server_type') === 'shared';
        if ($shared || (! $shared && $this->application->domain_option === 'parent')) {
            return $this->parent ? $this->parent->domain() : null;
        } elseif ($this->primary_domain) {
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
        $setting = 'override.'.$setting;
        if ($this->settings != null) {
            if (is_array($this->settings)) {
                $settings = $this->settings;
            } else {
                $settings = json_decode($this->settings, true);
            }
        }

        return Arr::get($this->settings, $setting);
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
            if (is_array($this->settings)) {
                $settings = $this->settings;
            } else {
                $settings = json_decode($this->settings, true);
            }

            return Arr::get($settings, $setting);
        }

        return $default;
    }

    public function updateSetting($setting, $value)
    {
        $settings = [];

        if ($this->settings != null) {
            if (is_array($this->settings)) {
                $settings = $this->settings;
            } else {
                $settings = json_decode($this->settings, true);
            }
        }

        Arr::set($settings, $setting, $value);

        $this->settings = $settings;
        $this->save();
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
