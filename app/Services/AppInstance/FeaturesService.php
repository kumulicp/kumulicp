<?php

namespace App\Services\AppInstance;

use App\AppInstance;
use App\AppPlan;
use App\AppRole;
use App\Integrations\Applications\AppFeature;
use App\Support\Facades\Application;
use Illuminate\Support\Arr;

class FeaturesService
{
    private $features = [];

    private $plan;

    private $app_instance_features;

    private $feature_info;

    public $app_instance;

    private $updated_features = [];

    public function __construct(AppInstance $app_instance)
    {
        $this->app_instance = Application::instance($app_instance);
        $this->build();
    }

    private function build()
    {
        $app_features = Application::features($this->app_instance->application->slug, $this->app_instance->get());
        foreach ($app_features as $app_feature) {
            $name = $app_feature->name;

            $this->features[$name] = array_merge(
                (array) $app_feature,
                [
                    'class' => $app_feature,
                    'plan_status' => $this->plan()?->featureValue("$name.status"),
                    'price' => $this->plan()?->featureValue("$name.price"),
                    'price_id' => $this->plan()?->featureValue("$name.price_id"),
                    'status' => $this->status($name),
                    'settings' => $this->settings($app_feature),
                    'override' => $this->override($name),
                ]);
        }
    }

    public function all()
    {
        return $this->features;
    }

    public function feature(string $name)
    {
        return $this->features[$name];
    }

    public function paidFeatures()
    {
        $features = collect();
        foreach ($this->all() as $feature) {
            if (Arr::get($feature, 'price') && Arr::get($feature, 'price_id')) {
                $features->push($feature);
            }
        }

        return $features;
    }

    public function active()
    {
        foreach ($this->features as $name => $feature) {
            if ($this->isActive($name)) {
                $features[$name] = $feature;
            }
        }

        return $features;
    }

    public function optional()
    {
        $this->build();
        $features = [];

        foreach ($this->features as $name => $feature) {
            if ($feature['plan_status'] == 'optional') {
                $features[$name] = $feature;
            }
        }

        return $features;
    }

    public function isActive(string $name)
    {
        $override = $this->hasOverride($name);

        return ($override && $this->app_instance->feature("$name.status") === 'enabled')
                || (! $override
                    && ($this->plan()->featureStatus($name) === 'enabled'
                    || ($this->plan()->featureStatus($name) === 'optional' && $this->app_instance->feature("$name.status") === 'enabled')));
    }

    public function update(array $features)
    {
        foreach ($this->optional() as $name => $feature) {
            $current_status = $feature['status'];
            $status = 'disabled';

            if (! $this->hasOverride($name) && array_key_exists($name, $features)) {
                if (is_string($features[$name])) {
                    $status = $features[$name] == true ? 'enabled' : 'disabled';
                } elseif (is_array($features[$name])) {
                    $status = $features[$name]['status'];
                } elseif (is_bool($features[$name])) {
                    $status = $features[$name] == true ? 'enabled' : 'disabled';
                }
            }

            $this->updateStatus($name, $status);

            if ($current_status !== $status) {
                array_push($this->updated_features, $this->features[$name]);
            }
        }
        $this->updateSettingFeatures();
    }

    public function updateSettingFeatures()
    {
        $parsed_features = collect($this->features)->map(function ($feature) {
            return [
                'name' => $feature['name'],
                'status' => $feature['status'],
                'override' => $feature['override'],
            ];
        });

        $this->app_instance->updateSetting('features', $parsed_features);
    }

    public function updatedFeatures()
    {
        return collect($this->updated_features);
    }

    public function hasRolesDependentOnUpdatedFeatures()
    {
        foreach ($this->updatedFeatures() as $feature) {
            // only features that are disabled will affect user permissions
            if ($feature['status'] === 'disabled') {
                if (AppRole::whereJsonContains('required_features', $feature['name'])->count() > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    public function setPlan(AppPlan $plan)
    {
        $this->plan = $plan;

        return $this;
    }

    private function plan()
    {
        if (! $this->plan) {
            $this->plan = $this->app_instance->plan;
        }

        return $this->plan;
    }

    private function status($name)
    {
        if ($this->hasOverride($name)) {
            return $this->app_instance->feature("$name.status");
        } else {
            $plan_feature_status = $this->plan()?->featureValue("$name.status");

            if ($plan_feature_status == 'enabled') {
                return 'enabled';
            } elseif ($plan_feature_status == 'optional') {
                $app_feature_status = $this->app_instance->feature("$name.status");

                return $app_feature_status ?? 'disabled';
            }
        }

        return 'disabled';
    }

    private function updateStatus($name, $status)
    {
        if (! $this->hasOverride($name)) {
            switch ($this->plan()->featureValue("$name.status")) {
                case 'enabled':
                    $this->features[$name]['status'] = 'enabled';
                    break;
                case 'disabled':
                    $this->features[$name]['status'] = 'disabled';
                    break;
                case 'optional':
                    $this->features[$name]['status'] = $status;
                    break;
            }
        }
    }

    public function settings(AppFeature $app_feature)
    {
        $name = $app_feature->name;
        $settings = [];
        $auto_settings = [];
        if ($this->hasOverride($name)) {
            $settings = $this->app_instance->feature("$name.settings");
        } else {
            $settings = $this->plan()?->featureValue("$name.settings") ?? [];
        }

        $auto_settings = $this->autoSettings($app_feature);

        return array_merge($settings, $auto_settings);
    }

    private function autoSettings(AppFeature $app_feature)
    {
        $settings = [];
        if (method_exists($app_feature, 'auto_settings')) {
            foreach ($app_feature->auto_settings() as $setting) {
                $settings[$setting['name']] = $setting['value'];
            }
        }

        return $settings;
    }

    private function updateSettings($name, array $settings)
    {
        if (! $this->hasOverride($name)) {
            $this->features[$name]['settings'] = $settings;
        }
    }

    public function appFeature($feature, $info = null)
    {
        switch (gettype($info)) {
            case 'string':
                $info_array = [$info];
                break;
            case 'array':
                $info_array = $info;
                break;
            default:
                $info_array = [];
                break;
        }

        $get = implode('.', array_merge([$feature], $info_array));

        return Arr::get($this->features, $get);
    }

    private function override($name)
    {
        return Arr::get($this->app_instance->feature($name), 'override', false) === true;
    }

    private function hasOverride($name)
    {
        return Arr::get($this->features, "$name.override", false) === true;
    }

    public function setFeatureOverride($name, $override = false)
    {
        $this->features[$name]['override'] = $override;
    }

    public function rebuild()
    {
        $this->build();

        return $this;
    }

    public function save()
    {
        $features = [];

        foreach ($this->features as $name => $feature) {
            if ($this->hasOverride($name) || $feature['plan_status'] == 'optional') {
                $features[$name] = [
                    'name' => $name,
                    'status' => $feature['status'],
                    'settings' => $feature['settings'],
                ];
            }
        }

        $this->app_instance->updateSetting('features', $features);
    }
}
