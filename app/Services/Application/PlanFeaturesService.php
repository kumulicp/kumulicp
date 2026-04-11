<?php

namespace App\Services\Application;

use App\AppPlan;
use App\Support\Facades\Application;
use Illuminate\Support\Arr;

class PlanFeaturesService
{
    private $features = [];

    public function __construct(private AppPlan $plan)
    {
        $this->application = $this->plan->application;

        $this->build();
    }

    private function build()
    {
        foreach (Application::profile($this->application->slug)->features() as $name => $feature) {
            $this->features[$name] = (array) $feature;
            $this->features[$name]['status'] = $this->plan->featureValue("$name.status") ?? 'disabled';
            $this->features[$name]['price'] = $this->plan->featureValue("$name.price");
            $this->features[$name]['price_id'] = $this->plan->featureValue("$name.price_id");
            $this->features[$name]['payment_type'] = $this->plan->featureValue("$name.payment_type");
            $this->features[$name]['settings'] = $this->plan->featureValue("$name.settings");

            foreach ($feature->admin_settings() as $setting_name => $admin_setting) {
                if (! Arr::has($this->features[$name]['settings'], $setting_name)) {
                    $this->features[$name]['settings'][$setting_name] = '';
                }
            }
        }
    }

    public function all()
    {
        return $this->features;
    }

    public function optional()
    {
        $features = [];

        foreach ($this->features as $name => $feature) {
            if ($feature['status'] == 'optional') {
                $features[$name] = $feature;
            }
        }

        return $features;
    }
}
