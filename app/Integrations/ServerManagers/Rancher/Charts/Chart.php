<?php

namespace App\Integrations\ServerManagers\Rancher\Charts;

use App\AppInstance;
use App\Organization;
use App\Support\Facades\Application;
use Illuminate\Support\Arr;

class Chart
{
    public $name = '';

    public $namespace;

    public function __construct(
        public Organization $organization,
        public AppInstance $app_instance,
    ) {
        $this->name = $this->app_instance->name.'-'.$this->app_instance->id;
        if (property_exists($this, 'chart_name')) {
            $this->name .= '-'.$this->chart_name;
        }
    }

    public function namespace()
    {
        return $this->namespace ?? $this->organization->slug;
    }

    public function extraEnv()
    {
        $name = $this->app_instance->application->slug;
        $env_vars = [];

        $app = Application::profile($name);
        foreach (Application::profile($name)->envs() as $env_class) {
            $env = new $env_class;
            foreach ($env->get($this->app_instance) as $name => $value) {
                $env_vars[] = [
                    'name' => $name,
                    'value' => $value,
                ];
            }
        }

        return array_merge($env_vars, $this->convertFeaturesToEnvVars());
    }

    public function convertFeaturesToEnvVars()
    {
        $app_instance_features = Application::instance($this->app_instance)->features();
        $app_features = Application::profile($this->app_instance->application->slug)->features();
        $app_features_keys = $app_features->keys()->all();

        $env_vars = [];
        foreach ($app_instance_features->all() as $name => $feature) {

            // Check if feature is registered
            if (in_array($name, $app_features_keys)) {
                $env_vars[] = [
                    'name' => $app_features[$name]->var_name,
                    'value' => $feature['status'] == 'enabled' ? '1' : '0',
                ];

                // If feature enabled, check for settings and include them in chart
                if ($feature['status'] == 'enabled') {
                    foreach ($feature['settings'] as $name => $setting) {
                        $env_vars[] = [
                            'name' => strtoupper($name),
                            'value' => $setting,
                        ];
                    }
                }
            }
        }

        return $env_vars;
    }

    public function sidecars()
    {
        $name = $this->app_instance->application->slug;
        $sidecars = [];

        $app = Application::profile($name)->sidecars();
        foreach (Arr::get($app, 'sidecars', []) as $sidecar_class) {
            $sidecar = new $sidecar_class;
            if ($get_sidecar = $sidecar->get($this->app_instance)) {
                $sidecars[] = $sidecar->get($this->app_instance);
            }
        }

        return $sidecars;
    }
}
