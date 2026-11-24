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
     * Where the app's chart reads the claim of its persistent volume and the settings of the
     * volume from, in the instance's settings (see App\Support\StorageHandover); null when
     * the chart has no way to be given a claim that already exists. Each entry has a `path`
     * (dot notation) and, where it differs, `as` ('list' for a value the chart takes as a list).
     * `claim` may also have `with` / `without`: settings that go with using or not using one.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function storageHandover(AppInstance $app_instance): ?array
    {
        return null;
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
     * The settings of a version made from what the profile recommends: its chart (name,
     * version and the repository it is published in) and its image. What the profile
     * doesn't recommend is left out.
     *
     * @return array<string, string>
     */
    public function recommendedVersionSettings(): array
    {
        $recommendations = $this->recommendations();

        return array_filter([
            'chart_name' => Arr::get($recommendations, 'helm_chart_name'),
            'chart_version' => Arr::get($recommendations, 'helm_chart_version'),
            'helm_repo_name' => Arr::get($recommendations, 'helm_chart_repo'),
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
