<?php

namespace App\Integrations\Applications;

use App\AppInstance;
use App\Support\Facades\Application;
use App\Updates\Aspects\Aspect;
use App\Updates\UpdateStep;
use Illuminate\Support\Arr;

class AppProfile
{
    protected $name = '';

    protected $sidecars = [];

    protected $envs = [];

    /** @var array<int, class-string<ChartModifier>> */
    protected $chart_modifiers = [];

    protected $features = [];

    /** @var array<int, UpdateStep> */
    protected $update_steps = [];

    /** @var array<int, Aspect> */
    protected $aspects = [];

    protected $configurations = [];

    /**
     * The volumes the app's chart deploys, by name, next to the configurations (see App\Support\Volumes\Volumes).
     * Each has the defaults of what a plan or an instance can change (`enabled`, `storage_class`, `access_mode`, and
     * `size` for a volume with a size of its own) and:
     *
     * - `size`: 'quota' for the volume that holds the customer's files, which follows what the subscription gives
     *   the app, or a number of Gi for a volume with a size of its own;
     * - `claim`: true if it can be given a claim that already exists, which only that volume can;
     * - `optional`: true if the app can do without it, so it is off until `enabled` says otherwise.
     *
     * The same name is used by the plan's settings (`volumes.<name>.*`), the instance's settings, the forms and the chart.
     * An app that declares none has the one every app has.
     *
     * @var array<string, array<string, mixed>>
     */
    protected $volumes = [
        'data' => [
            'label' => 'admin.volumes.data',
            'size' => 'quota',
            'claim' => true,
            'enabled' => true,
            'storage_class' => '',
            'access_mode' => 'ReadWriteOnce',
        ],
    ];

    protected $roles = [];

    protected $role_groups = [];

    protected $recommendations = [];

    protected $jobs;

    protected $helm_chart = '';

    protected $app;

    /**
     * Can be chart or job
     */
    protected $activation_type = 'chart';

    /**
     * App checks compatibility for openid, sso, ldap, additional_user_storage, additional_storage, multisite
     */
    protected $compatibility = [];

    public function name()
    {
        return $this->name;
    }

    public function sidecars()
    {
        return $this->sidecars;
    }

    public function feature(string $feature)
    {
        $feature = Arr::get($this->features, $feature);

        if (class_exists($feature)) {
            return new $feature;
        }
    }

    public function features()
    {
        $features = [];
        foreach ($this->features as $name => $feature) {
            if (is_object($feature)) {
                $features[$name] = $feature;
            } elseif (class_exists($feature)) {
                $features[$name] = new $feature;
            }
        }

        return collect($features);
    }

    /**
     * Where the volume that holds the app's files, and can be given a claim that exists, keeps what a move gives it
     * (App\Support\StorageHandover), in the instance's settings; null when the app has none. It is the same for every
     * app, since they all keep a volume at `volumes.<name>` (see App\Support\Volumes\Volumes). Each entry has a `path`
     * (dot notation).
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function storageHandover(AppInstance $app_instance): ?array
    {
        if (! ($this->volumes()['data']['claim'] ?? false)) {
            return null;
        }

        return [
            'claim' => ['path' => 'volumes.data.claim'],
            'access_mode' => ['path' => 'volumes.data.access_mode'],
            'storage_class' => ['path' => 'volumes.data.storage_class'],
            'size' => ['path' => 'volumes.data.size'],
        ];
    }

    public function configuration(string $config)
    {
        return Arr::get($this->configurations, $config);
    }

    public function configurations()
    {
        return $this->configurations;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function volumes(): array
    {
        return $this->volumes;
    }

    /**
     * Adds a configuration (a plan setting), for an extension that brings a
     * setting of its own. Same shape as the entries of the profile's $configurations.
     *
     * @param  array<string, mixed>  $definition
     * @return $this
     */
    public function addConfiguration(string $name, array $definition)
    {
        $this->configurations[$name] = $definition;

        return $this;
    }

    /** @return array<int, class-string<ChartModifier>> */
    public function chartModifiers(): array
    {
        return $this->chart_modifiers;
    }

    public function chart()
    {
        return $this->helm_chart;
    }

    public function roleGroups()
    {
        return $this->role_groups;
    }

    public function envs()
    {
        return $this->envs;
    }

    public function role(string $role): ?array
    {
        return Arr::get($this->roles, $role, null);
    }

    public function roles()
    {
        return $this->roles;
    }

    public function jobs()
    {
        return $this->jobs;
    }

    public function recommendations()
    {
        return $this->recommendations;
    }

    /**
     * The chart the profile recommends: its name and the repository it is published in.
     * It is the app's chart, not a version's. What the profile doesn't recommend is left out.
     *
     * @return array<string, string>
     */
    public function recommendedChart(): array
    {
        $recommendations = $this->recommendations();

        return array_filter([
            'chart_name' => Arr::get($recommendations, 'helm_chart_name'),
            'helm_repo' => Arr::get($recommendations, 'helm_chart_repo'),
        ], fn ($value) => $value !== null);
    }

    /**
     * The settings of a version made from what the profile recommends: the version of
     * its chart and its image. What the profile doesn't recommend is left out.
     *
     * @return array<string, string>
     */
    public function recommendedVersionSettings(): array
    {
        $recommendations = $this->recommendations();

        return array_filter([
            'chart_version' => Arr::get($recommendations, 'helm_chart_version'),
            'image_repo_name' => Arr::get($recommendations, 'image_repo'),
            'image_registry' => Arr::get($recommendations, 'image_registry'),
        ], fn ($value) => $value !== null);
    }

    public function activationType(?AppInstance $app_instance = null)
    {
        return $this->activation_type;
    }

    /**
     * The username of the app's local admin account, the one the instance's API
     * password belongs to. Not the user's own SSO account: it is what an admin
     * signs in with when SSO is broken. Apps whose admin is not 'support' (or
     * their own `username` configuration) say so.
     */
    public function adminUsername(AppInstance $app_instance): string
    {
        return Application::instance($app_instance)->configuration('username') ?? 'support';
    }

    /**
     * Whether the instance has a site of its own: users, permissions and
     * customizations. False for a shared app that is only the container other
     * organizations activate their own instances inside of (ERPNext's bench,
     * database and sockets), which is deployed but never used itself.
     */
    public function hasOwnSite(?AppInstance $app_instance = null): bool
    {
        return true;
    }

    public function isCompatible(string|array $compatibility): bool
    {
        if (is_array($compatibility)) {
            foreach ($compatibility as $flag) {
                if (in_array($flag, $this->compatibility)) {
                    return true;
                }
            }

            return false;
        }

        return in_array($compatibility, $this->compatibility);
    }

    /**
     * Whether the app can sign people in through a single sign-on server: it declares one of
     * the ways to (the same flags the plan forms use to offer the choice).
     */
    public function supportsSso(): bool
    {
        return $this->isCompatible(['sso', 'openid', 'oauth2', 'saml']);
    }

    public function compatibility()
    {
        return $this->compatibility;
    }

    /**
     * Every compatibility flag any app profile can declare, with a human
     * label + brief description -- used to show admins which of these a
     * given app actually supports.
     */
    public static function compatibilityCatalog(): array
    {
        $flags = [
            'rancher',
            'helm_chart',
            'openid',
            'sso',
            'ldap',
            'shareable',
            'multisite',
            'additional_user_storage',
            'additional_storage',
        ];

        $catalog = [];

        foreach ($flags as $flag) {
            $catalog[$flag] = [
                'label' => __("admin.applications.compatibility.$flag.label"),
                'description' => __("admin.applications.compatibility.$flag.description"),
            ];
        }

        return $catalog;
    }

    public function replaceJobs($jobs)
    {
        if (class_exists($jobs)) {
            $this->jobs = $jobs;
        }

        return $this;
    }

    public function addEnvVars($env_vars)
    {
        if (class_exists($env_vars)) {
            array_push($this->envs, $env_vars);
        }

        return $this;
    }

    /**
     * Adds a change to the chart this app deploys (see ChartModifier).
     *
     * @param  class-string<ChartModifier>  $modifier
     * @return $this
     */
    public function addChartModifier(string $modifier)
    {
        if (class_exists($modifier) && ! in_array($modifier, $this->chart_modifiers, true)) {
            $this->chart_modifiers[] = $modifier;
        }

        return $this;
    }

    public function addSidecar($sidecar)
    {
        if (class_exists($sidecar)) {
            array_push($this->sidecars, $sidecar);
        }

        return $this;
    }

    /**
     * Adds a step to the update of this app (see UpdateStep).
     *
     * @return $this
     */
    public function addUpdateStep(UpdateStep $step)
    {
        $this->update_steps[] = $step;

        return $this;
    }

    /** @return array<int, UpdateStep> */
    public function updateSteps(): array
    {
        return $this->update_steps;
    }

    /**
     * Adds an aspect of this app that can be out of date (see Aspect). Name it
     * in the aspects() of the update step that brings it up to date.
     *
     * @return $this
     */
    public function addAspect(Aspect $aspect)
    {
        $this->aspects[] = $aspect;

        return $this;
    }

    /** @return array<int, Aspect> */
    public function aspects(): array
    {
        return $this->aspects;
    }

    public function addFeature(AppFeature $feature)
    {
        $this->features[$feature->name] = $feature;

        return $this;
    }
}
