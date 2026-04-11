<?php

namespace App\Integrations\Applications;

use Illuminate\Support\Arr;

class AppProfile
{
    protected $sidecars = [];

    protected $envs = [];

    protected $features = [];

    protected $configurations = [];

    protected $roles = [];

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

    public function configuration(string $config)
    {
        return Arr::get($this->configurations, $config);
    }

    public function configurations()
    {
        return $this->configurations;
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

    public function activationType()
    {
        return $this->activation_type;
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

    public function compatibility()
    {
        return $this->compatibility;
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

    public function addSidecar($sidecar)
    {
        if (class_exists($sidecar)) {
            array_push($this->sidecars, $sidecar);
        }

        return $this;
    }

    public function addFeature(AppFeature $feature)
    {
        $this->features[$feature->name] = $feature;

        return $this;
    }
}
