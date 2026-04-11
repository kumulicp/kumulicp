<?php

namespace App\Services\Application;

use App\Actions\Organizations\UpdateSubscriptionSettings;
use App\AppPlan;
use App\Support\Facades\Action;
use Illuminate\Support\Arr;

class AppPlanService
{
    private $features;

    public function __construct(public AppPlan $plan) {}

    public function features()
    {
        if (! $this->features) {
            $this->features = new PlanFeaturesService($this->plan);
        }

        return $this->features;
    }

    public function setDefault()
    {
        $current_default_plan = $this->default();
        if ($current_default_plan) {
            if ($current_default_plan->id != $this->plan->id) {
                // Replace old default with new one
                $current_default_plan->is_default = false;
                $current_default_plan->save();

                $this->plan->is_default = false;
            }
        } else {
            $this->plan->is_default = true;
        }

        $this->plan->save();
    }

    public function default()
    {
        $current_default_plan = AppPlan::where('application_id', $this->plan->application_id)->where('is_default', true)->first();

        return $current_default_plan;
    }

    public function featureSettings($name)
    {
        return Arr::get($this->plan->settings, "features.$name");
    }

    public function configuration($name)
    {
        return Arr::get($this->plan->settings, "configurations.$name");
    }

    public function updateFeatures(array $features)
    {
        $plan_changed = false;

        foreach ($features as $name => $feature) {
            if (is_string($feature)) {
                $status = $feature == 'on' ? 'enabled' : 'disabled';
            } else {
                $status = $feature['status'];
            }

            $plan_settings[$name] = [
                'name' => $name,
                'status' => $status,
                'price' => Arr::get($feature, 'price'),
                'price_id' => Arr::get($feature, 'price_id'),
                'payment_type' => Arr::get($feature, 'payment_type'),
                'settings' => Arr::get($feature, 'settings', []),
            ];

            if ($plan_settings[$name] != $this->featureSettings($name)) {
                $plan_changed = true;
            }
        }

        $this->plan->updateSetting('features', $plan_settings);

        if ($plan_changed) {
            Action::execute(new UpdateSubscriptionSettings($this->plan));
        }

        return $plan_settings;
    }

    public function basePrice()
    {
        return $this->plan->setting('base.price');
    }

    public function baseStorage()
    {
        return $this->plan->setting('base.storage');
    }

    public function domainsEnabled()
    {
        return $this->plan->domain_enabled;
    }

    public function emailEnabled()
    {
        return $this->plan->email_enabled;
    }

    public function hasUserStorage()
    {
        return ! is_null($this->plan->setting('standard.storage'));
    }

    public function __get($property)
    {
        return $this->plan->$property;
    }

    public function __call($method, $args)
    {
        return $this->plan->$method(...$args);
    }
}
