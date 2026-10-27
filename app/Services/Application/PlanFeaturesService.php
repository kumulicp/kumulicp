<?php

namespace App\Services\Application;

use App\AppPlan;
use App\Integrations\Applications\AppFeature;
use App\Support\Facades\Application;
use Illuminate\Support\Arr;

class PlanFeaturesService
{
    private $features = [];

    private $application;

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
            // Legacy flat price/price_id preserved for display; per-currency prices live in prices.*
            $this->features[$name]['price'] = $this->plan->featureValue("$name.price");
            $this->features[$name]['price_id'] = $this->plan->featureValue("$name.price_id");
            $this->features[$name]['prices'] = $this->plan->featureValue("$name.prices") ?? [];
            $this->features[$name]['payment_type'] = $this->plan->featureValue("$name.payment_type");
            $this->features[$name]['settings'] = $this->plan->featureValue("$name.settings");

            if (method_exists($feature, 'admin_settings')) {
                foreach ($feature->admin_settings() as $setting_name => $admin_setting) {
                    if (! Arr::has($this->features[$name]['settings'], $setting_name)) {
                        $this->features[$name]['settings'][$setting_name] = '';
                    }
                }
            }

            $this->maskSecretSettings($name, $feature);
        }
    }

    // Secret settings never leave the server. Each value is blanked, and
    // 'secrets_set' records only whether one is saved, so the admin UI can show
    // "set" and treat a blank submission as "keep what is stored".
    private function maskSecretSettings(string $name, $feature): void
    {
        $this->features[$name]['secrets_set'] = [];

        $secret_settings = $feature instanceof AppFeature ? $feature->secretSettings() : [];

        foreach ($secret_settings as $setting_name) {
            // Read the value before it is blanked
            $this->features[$name]['secrets_set'][$setting_name] = ! empty($this->features[$name]['settings'][$setting_name]);
            $this->features[$name]['settings'][$setting_name] = '';
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
