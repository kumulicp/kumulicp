<?php

namespace App\Services;

use App\Actions\Apps\ApplicationDomainChecks;
use App\Actions\Apps\ApplicationUpdateJob;
use App\AppInstance;
use App\Events\Apps\AppInstanceDomainChanged;
use App\Integrations\ServerManagers\Rancher\Charts\HelmChart;
use App\Organization;
use App\OrgSubdomain;
use App\Services\AppInstance\AppInstancePlanService;
use App\Services\AppInstance\AppStorageService;
use App\Services\AppInstance\FeaturesService;
use App\Services\Application\AppPlanService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Application;
use App\Support\Facades\Domain;
use App\Support\Facades\ServerInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AppInstanceService
{
    private $features;

    private $standard_users;

    private $basic_users;

    private $application;

    private $role_groups;

    private $storage;

    private $cache = [];

    public function __construct(public AppInstance $app_instance) {}

    public function features()
    {
        if (! $this->features) {
            $this->features = new FeaturesService($this->app_instance);
        }

        return $this->features;
    }

    public function get()
    {
        return $this->app_instance;
    }

    public function connect($server_type)
    {
        $server = $server_type.'_server_id';
        $org_server = $this->server($server_type);

        if (! $org_server) {
            throw new \Exception(__('messages.exception.server_not_set', ['server_type' => $server_type, 'app' => $this->app_instance->application->name]));
        }

        return ServerInterface::connect($org_server->org_server, $this->app_instance);
    }

    public function charts()
    {
        $organization = $this->app_instance->organization;
        $app_name = $this->app_instance->application->slug;
        $charts = Application::profile($app_name)->chart();

        $return_charts = [];

        if (is_array($charts)) {
            foreach ($charts as $chart) {
                $helm_chart = new $chart($organization, $this->app_instance);

                if (is_a($helm_chart, HelmChart::class)) {
                    $return_charts[] = $helm_chart;
                }
            }
        } elseif (class_exists($charts)) {
            $helm_chart = new $charts($organization, $this->app_instance);
            if (is_a($helm_chart, HelmChart::class)) {
                $return_charts[] = $helm_chart;
            }
        }

        return $return_charts;
    }

    public function server(string $type)
    {
        $server = $type.'_server';
        $org_server = $this->app_instance->$server;

        if (! $org_server) {
            $org_server = OrgServerService::add($this->app_instance->organization, $type, $this->app_instance->plan);

            if ($org_server) {
                $this->app_instance->$server()->associate($org_server->org_server);
                $this->app_instance->save();
            }
        } else {
            $org_server = new OrgServerService($org_server);
        }

        return $org_server;
    }

    public function backupServer(string $type)
    {
        $org_server = $this->server($type);

        return $org_server?->backupServer();
    }

    public function updateSettings()
    {
        $task = null;

        if (! Action::exists(
            organization: $this->app_instance->organization,
            category: 'system',
            action: 'application_update'
        )) {
            $task = Action::dispatch(
                category: 'system',
                action: 'application_update',
                params: [$this->app_instance, 'update_settings']
            );
        }

        return $task;
    }

    public function plan(?AppPlan $plan = null)
    {
        if (! $plan) {
            $plan = $this->app_instance->plan;
        }

        return new AppPlanService($plan, $plan->application);
    }

    public function updatePrimaryDomain(?OrgSubdomain $domain = null)
    {
        if ($domain && ! $domain->is($this->app_instance->primary_domain)) {
            if (! Domain::ipPointsToServer($domain, $this->server('web')->server)) {
                Action::execute(new ApplicationDomainChecks($this->app_instance, $domain));
            } elseif ($this->app_instance->primary_domain_id != $domain->id) {
                $domain->app_instance_id = $this->app_instance->id;
                $domain->save();

                $this->app_instance->primary_domain()->associate($domain);
                $this->app_instance->save();

                Action::clear('application_domain_checks', $this->app_instance);

                Action::execute(new ApplicationUpdateJob($this->app_instance, 'update_domain'));

                AppInstanceDomainChanged::dispatch($this->app_instance);
            }
        } elseif (! $domain && $this->app_instance->primary_domain_id) {
            $this->app_instance->primary_domain()->dissociate();
            $this->app_instance->save();

            Action::clear('application_domain_checks', $this->app_instance);

            Action::execute(new ApplicationUpdateJob($this->app_instance, 'update_domain'));

            AppInstanceDomainChanged::dispatch($this->app_instance);
        } else {
            Action::clear('application_domain_checks', $this->app_instance);
        }

        return $this;
    }

    public function configuration($configuration, $required = false)
    {
        $settings = $this->app_instance->settings;

        $config = Application::profile($this->app_instance->application)->configuration($configuration);
        $config_persistent = Arr::get($config, 'persistent', false) || Arr::get($this->app_instance->plan?->additionalConfigs(), "$configuration.persistent", false);
        $config_personalized = Arr::get($config, 'personalized', false);

        // Check if value is overriden
        if (! $config_persistent && Arr::has($settings, "override.$configuration")) {
            return Arr::get($settings, "override.$configuration");
            // Check if a config is persistent and if so, get value from
        } elseif (($config_persistent && Arr::has($settings, "configurations.$configuration"))
            || ($config_personalized && Arr::has($settings, "configurations.$configuration"))
        ) {
            return Arr::get($settings, "configurations.$configuration");
            //  Check if plan has configuration set
        } elseif ($plan_setting = $this->app_instance->plan?->setting("configurations.$configuration")) {
            return $plan_setting;
            // If all else fails, get the default from the app's profiles
        } elseif (Arr::has($config, 'default')) {
            return Arr::get($config, 'default');
        }

        if ($required) {
            throw new \Exception("Could not obtain {$this->app_instance->application->name} configuration $configuration");
        }

        return null;
    }

    public function updateRedirectDomains(string $type = 'all')
    {
        $this->connect('web')->updateRedirectDomains();

        return $this;
    }

    public function hasParent()
    {
        return $this->app_instance->parent ? true : false;
    }

    public function hasCustomizations()
    {
        return count($this->customizations()) > 0;
    }

    public function customizations()
    {
        $plan = $this->app_instance->plan;

        $plan_service = new AppInstancePlanService($plan, $this->app_instance);

        return $plan_service->customizations();
    }

    public function updateCustomizations()
    {
        $task = null;

        foreach ($this->customizations() as $customization) {
            $attributes = [
                'name' => $customization['name'],
                'status' => $customization['status'],
                'settings' => $customization['settings'],
            ];

            if (array_key_exists('action', $customization) && class_exists($customization['action'])) {
                $action = $customization['action'];
                $task = Action::execute(new $action($this->app_instance, $attributes), $task);
            } elseif (method_exists($customization['class'], 'action')) {
                $customization['class']->action();
            }
        }
    }

    public function availableDomains()
    {
        $domains = collect();

        $organization = auth()->user()->organization;
        $domains = $domains->merge($this->orgSubdomains($organization));

        foreach ($organization->suborganizations as $suborg) {
            $domains = $domains->merge($this->orgSubdomains($suborg));
        }

        return $domains;
    }

    public function orgSubdomains(Organization $organization)
    {
        $domain_option = $this->app_instance->application->domain_option;
        // Base domain will return nothing
        if (! in_array($domain_option, ['base', 'parent', 'none'])) {
            $domains = $organization->subdomains();
            // Get Primary domains
            if ($domain_option === 'primary') {
                $domains->where('host', '@');
            }

            // Get only subdomains
            if ($domain_option === 'subdomains') {
                $domains->whereNot('host', '@');
            }

            $domains->where(function ($query) {
                return $query->where('app_instance_id', $this->id)
                    ->orWhere('app_instance_id', 0)
                    ->orWhere('app_instance_id', null);
            })
                ->where('type', 'app');

            return $domains->get();
        }

        return collect();
    }

    public function isSubdomainAvailable(OrgSubdomain $domain)
    {
        $domain_option = $this->app_instance->application->domain_option;

        return in_array($domain->app_instance_id, [$this->id, 0, null])
            && $domain->type === 'app'
            && ! in_array($domain_option, ['base', 'parent', 'none'])
            && (($domain_option === 'primary'
                    && $domain->host === '@')
                || ($domain_option === 'subdomains'
                    && $domain->host !== '@')
                || ($domain_option === 'all'));
    }

    public function personalized_settings()
    {
        $app_instance = $this->app_instance;

        return collect(Application::configurations($this->app_instance->application, $this->app_instance->plan, true))->filter(function ($config) {
            return Arr::get($config, 'personalized', false);
        })->map(function ($config) use ($app_instance) {
            $config['label'] = Str::headline($config['name']);
            $config['value'] = $app_instance->setting('configurations.'.$config['name']) ?? $config['value'];

            return $config;
        });
    }

    public function countEntity($entity)
    {
        if (! Arr::has($this->cache, "count.$entity")) {
            $method = 'count'.ucfirst($entity);
            Arr::set($this->cache, "count.$entity", $this->$method());
        }

        return Arr::get($this->cache, "count.$entity");
    }

    public function countUsers()
    {
        return AccountManager::users()->appUsers($this->app_instance);
    }

    public function standard_users()
    {
        return AccountManager::users()->appStandardUsers($this->app_instance);
    }

    public function basic_users()
    {
        return AccountManager::users()->appBasicUsers($this->app_instance);
    }

    public function countBase()
    {
        return 1;
    }

    public function countStandard()
    {
        return count($this->standard_users());
    }

    public function countBasic()
    {
        return count($this->basic_users());
    }

    public function countStorage()
    {
        $additional_storage = 0;

        foreach ($this->additional_storage()->get() as $storage) {
            $additional_storage += $storage->quantity ?? 0;
        }

        return $additional_storage;
    }

    public function storage()
    {
        return new AppStorageService($this->app_instance);
    }

    public function roles(bool $all = true)
    {
        return $this->version->roles(all: $all);
    }

    public function enabledGroups()
    {
        $roles = [];
        foreach ($this->roles() as $role) {
            $role_enabled = true;
            if ($role->required_features && count($role->required_features) > 0) {
                foreach ($role->required_features as $feature) {
                    if ($feature && ! Application::instance($this->app_instance)->features()->isActive($feature)) {
                        $role_enabled = false;
                        break;
                    }
                }
            }

            if ($role_enabled) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    public function childrenGroups()
    {
        $roles = collect();
        foreach ($this->children as $child) {
            $roles = $roles->merge(Application::instance($child)->roles()->all());
        }

        return $roles;
    }

    public function allRoles()
    {
        $roles = $this->roles();
        $children_groups = $this->childrenGroups();

        foreach ($roles as $role) {
            if ($role->implied_roles->count() > 0) {
                $roles = $roles->merge($role->implied_roles);
            }
        }
        if ($children_groups->count() > 0) {
            $roles = $roles->merge($this->childrenGroups());
        }

        return $roles;
    }

    public function standardRoles()
    {
        if (! $this->role_groups) {
            $this->role_groups = $this->version->roles('standard');
        }

        return $this->role_groups;
    }

    public function basicRoles()
    {
        if (! $this->role_groups) {
            $this->role_groups = $this->version->roles('basic');
        }

        return $this->role_groups;
    }

    public function application()
    {
        if (! $this->application) {
            $this->application = $this->app_instance->application;
        }

        return $this->application;
    }

    public function __get($property)
    {
        return $this->app_instance->$property;
    }

    public function __call($method, $args = null)
    {
        return $this->app_instance->$method(...$args);
    }

    public function __set($property, $value)
    {
        $this->app_instance->$property = $value;
    }
}
