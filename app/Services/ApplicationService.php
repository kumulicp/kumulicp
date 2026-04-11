<?php

namespace App\Services;

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppRole;
use App\AppVersion;
use App\Integrations\Applications\AppProfile;
use App\Integrations\Applications\CiviCRMStandalone\CiviCRMStandaloneProfile;
use App\Integrations\Applications\GenericAppProfile;
use App\Integrations\Applications\Nextcloud\NextcloudProfile;
use App\Integrations\Applications\Wordpress\WordpressProfile;
use App\Organization;
use App\OrgSubdomain;
use App\Services\Application\AppPlanService;
use App\Support\Facades\Organization as OrgFacade;
use App\Support\Facades\Subscription;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class ApplicationService
{
    public $plans = [];

    private $applications = [];

    private $organization;

    private $activated_apps;

    private $instances = [];

    public function __construct(private $app)
    {
        $this->register(new GenericAppProfile);
        $this->register(new NextcloudProfile);
        $this->register(new WordpressProfile);
        $this->register(new CiviCRMStandaloneProfile);
    }

    public function isRegistered(string $app)
    {
        return array_key_exists($app, $this->applications);
    }

    public function register(AppProfile $profile)
    {
        $name = $profile->name();

        if (! $this->isRegistered($name)) {
            $this->applications[$name] = $profile;
        }
    }

    public function profile(Application|string $app)
    {
        if (is_a($app, Application::class)) {
            $app = $app->slug;
        }

        return Arr::get($this->applications, $app, Arr::get($this->applications, 'generic'));
    }

    public function roles(Application|string $app)
    {
        $app = is_string($app) ? Application::where('slug', $app)->with('roles')->first() : $app;
        $app_name = is_string($app) ? $app : $app->slug;
        $app_roles = [];

        foreach ($app->roles as $role) {
            $app_roles[] = $role->slug;
        }

        $roles = [];
        $num = 0;

        if ($this->profile($app)?->roles()) {
            $role_groups = $this->profile($app)->roleGroups();

            foreach ($role_groups as $group_slug => $group_info) {
                Arr::pull($roles, "$group_slug.roles");
                foreach ($group_info['roles'] as $role_name) {
                    $role_info = $this->profile($app_name)->role($role_name);
                    Arr::set($roles, "$group_slug.roles.$role_name", $role_info);
                    if ($role_info && ! in_array($role_info['id'], $app_roles)) {
                        $app_role = new AppRole;
                        $app_role->application_id = $app->id;
                        $app_role->name = $role_info['label'];
                        $app_role->label = $role_info['label'];
                        $app_role->access_type = Arr::get($role_info, 'access_type', 'minimal');
                        $app_role->slug = $role_info['id'];
                        $app_role->category = $group_info['label'];
                        $app_role->description = Arr::get($role_info, 'description', '');
                        $app_role->status = 'enabled';
                        $app_role->save();
                    }
                }
            }
        }

        return $roles;
    }

    public function configurations(Application $app, ?AppPlan $plan = null, $raw = false)
    {
        $options = $this->profile($app->slug)->configurations();
        $configs = [];

        if (count($options) > 0) {
            foreach ($options as $name => $option) {
                if ($plan) {
                    if ($raw && $option['type'] == 'json') {
                        $options[$name]['value'] = json_encode($plan->setting("configurations.$name") ?? $option['default'] ?? []);
                    } elseif ($raw && $option['type'] == 'password') {
                        $options[$name]['value'] = '';
                    } elseif ($raw && $option['type'] === 'enum') {
                        $options[$name]['value'] = $plan->setting("configurations.$name") ?? $option['default'] ?? null;
                    } else {
                        $options[$name]['value'] = $plan->setting("configurations.$name") ?? $option['default'] ?? null;
                    }
                }
            }
        }

        return $options;
    }

    public function personalizedConfigurations(Application $app, AppPlan $plan)
    {
        return collect($this->configurations($app, $plan, true))->filter(function ($config) {
            return Arr::get($config, 'personalized', false);
        })->map(function ($config) {
            $config['label'] = Str::headline($config['name']);

            return $config;
        });
    }

    public function validateConfigurations(Application $app, $personalized = false)
    {
        $options = $this->profile($app)->configurations();
        $validations = [];

        foreach ($options as $name => $option) {
            if (! $personalized || ($personalized && Arr::get($option, 'personalized', false))) {
                $validations["configurations.$name"] = Arr::get($option, 'validations', '');
            }
        }

        return $validations;
    }

    public function processConfigurations(Application $app, AppPlan $plan, $configs)
    {
        $options = $this->profile($app)->configurations();

        foreach ($options as $name => $option) {
            if (Arr::has($configs, $name)) {
                switch ($option['type']) {
                    case 'int':
                        $configs[$name] = (int) $configs[$name];
                        break;
                    case 'bool':
                        $configs[$name] = in_array($configs[$name], [true, 1, '1', 'on']);
                        break;
                    case 'json':
                        $configs[$name] = json_decode(str_replace(["\n", "\t", "\r"], '', $configs[$name]));
                        break;
                    case 'password':
                        if (Arr::get($configs, $name, null)) {
                            try {
                                Crypt::decryptString($configs[$name]);
                            } catch (DecryptException $e) {
                                $configs[$name] = Crypt::encryptString($configs[$name]);
                            }
                        } else {
                            $configs[$name] = $plan->setting("configurations.$name");
                        }
                        break;
                    default:
                }
            } else {
                $configs[$name] = $option['default'];
            }
        }

        return $configs;
    }

    public function persistentConfigurations(Application $app, AppPlan $plan)
    {
        $configs = [];

        foreach ($this->configurations($app, $plan) as $name => $option) {
            if (Arr::get($option, 'persistent', false)) {
                $configs[$name] = $option['value'];
            }
        }

        return $configs;
    }

    public function all()
    {
        $db_applications = Application::with('roles')->get();
        $app_list = [];
        foreach ($db_applications as $app) {
            $app_list[] = $app->slug;
            $role_groups = $this->roles($app);
        }

        foreach ($this->applications as $application) {
            if (! in_array($application->name(), $app_list)) {
                $db_apps = $this->initialize($application->name());
            }
        }

        return $db_applications = Application::with('roles')->get();
    }

    public function instances(Organization $organization, $app_name)
    {
        $applications = Application::with(['instances' => function ($query) use ($organization) {
            $query->where('organization_id', $organization->id);
        }])->where('slug', $app_name)->first();

        foreach ($applications->instances as $app_instance) {
            $this->instances[$app_name][$app_instance->id] = $app_instance;
        }

        if (array_key_exists($app_name, $this->instances)) {
            return $this->instances[$app_name];
        }

        return [];
    }

    public function runJob(AppInstance $app_instance, string $job_name)
    {
        $app_name = $app_instance->application->slug;
        $job_class = $this->profile($app_instance->application)->jobs();
        if ($job_class && class_exists($job_class)) {
            $job = new $job_class($app_instance->organization, $app_instance);
        }

        $job_command = Str::camel($job_name);

        if ($job_class && method_exists($job_class, $job_command)) {
            return $this->instance($app_instance)->connect('web')->runJob($job->$job_command());
        }

        return null;
    }

    public function get(Application|string $app)
    {
        if (is_a($app, Application::class)) {
            $app = $app->slug;
        }

        return Arr::get($this->applications, $app);
    }

    public function initialize(string $app_name)
    {
        if (Arr::has($this->applications, $app_name)) {
            $application = new Application;
            $application->slug = $app_name;
            $application->name = Str::headline($app_name);
            $application->enabled = false;
            $application->save();

            return $application;
        }
    }

    public function activate(Organization $organization, Application $application, AppVersion $version, AppPlan $plan, ?AppInstance $parent_app = null, ?string $label = null, ?OrgSubdomain $domain = null)
    {
        $app_instance = new AppInstance;
        $app_instance->name = $application->slug.'-'.Str::lower(Str::random(5));
        $app_instance->label = $label ?? $application->name;
        $app_instance->organization_id = $organization->id;
        $app_instance->application_id = $application->id;
        $app_instance->version_id = $version->id;
        $app_instance->status = 'activating';
        $app_instance->api_password = Crypt::encrypt(Str::random(15));
        $app_instance->plan_id = $plan->id;

        if ($plan->setting('expires_after') > 0) {
            $app_instance->deactivate_at = now()->addDays($plan->setting('expires_after'));
        }

        if ($plan->setting('trial_for') > 0) {
            $app_instance->trial_ends_at = now()->addDays($plan->setting('trial_for'));
        }

        if ($parent_app) {
            $app_instance->parent()->associate($parent_app);
        }
        $app_instance->updateSetting('configurations', $this->persistentConfigurations($application, $plan));

        if ($domain) {
            $app_instance->primary_domain()->associate($domain);

            $domain->app_instance()->associate($app_instance);
            $domain->save();
        }
        $app_instance->save();

        $this->activated_apps[$application->slug] = $app_instance;

        return new AppInstanceService($app_instance);
    }

    public function instance(AppInstance $app_instance)
    {
        $app_id = is_string($app_instance) ? $app_instance : $app_instance->id;
        if (! Arr::has($this->instances, $app_id) || ! is_a($this->instances[$app_id], AppInstanceService::class)) {
            $app_instance = is_string($app_instance) ? OrgFacade::account()->app_instances()->where('name', $app_instance)->first() : $app_instance; // TODO
            $this->instances[$app_id] = new AppInstanceService($app_instance);
        }

        return $this->instances[$app_id];
    }

    // Needed here instead of AppInstanceService because some parent apps needed before app is activated
    public function availableParents(Application $app)
    {
        $organization = Organization::account();
        $collection = collect();

        if ($app->parent_app) {
            $collection = $app->parent_app->instances()->where('organization_id', $organization->id)->doesntHave('children')->get();

            foreach ($organization->suborganizations as $sub) {
                $apps = $app->parent_app->instances()->where('organization_id', $sub->id)->doesntHave('children')->get();
                if (count($apps) > 0) {
                    $collection = $collection->merge($apps);
                }
            }
        }

        return $collection;
    }

    public function features($app_slug, ?AppInstance $app_instance = null)
    {
        $features = [];

        foreach ($this->profile($app_slug)->features() as $name => $feature) {
            if ($app_instance) {
                $features[$name] = new $feature($app_instance);
            } else {
                $features[$name] = new $feature;
            }
        }

        return collect($features);
    }

    public function availablePlans(Application $app, Organization $organization, bool $display_order = false)
    {
        $slug = $app->slug;

        if (! Arr::has($this->plans, $slug)) {
            $this->plans[$slug] = Subscription::base()->appPlans($app, archived: false, display_order: $display_order);
        }

        return $this->plans[$slug];
    }

    public function plan(AppPlan $plan)
    {
        return new AppPlanService($plan);
    }
}
