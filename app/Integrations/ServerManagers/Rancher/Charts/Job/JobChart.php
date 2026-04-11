<?php

namespace App\Integrations\ServerManagers\Rancher\Charts\Job;

use App\Integrations\ServerManagers\Rancher\Charts\Chart;
use App\Support\Facades\Application;
use Illuminate\Support\Arr;

class JobChart extends Chart
{
    public function persistentVolumeClaim()
    {
        if ($pvc_name = $this->app_instance->getOverride('pvc.name')) {
            return $pvc_name;
        }

        $app = Application::instance($this->app_instance)->connect('web')->get();

        if (Arr::get($app, 'status') == 'success') {
            foreach (Arr::get($app, 'response.spec.resources', []) as $resource) {
                if (Arr::get($resource, 'kind') == 'PersistentVolumeClaim') {
                    $this->app_instance->updateSetting('override.pvc.name', $resource['name']);

                    return $resource['name'];
                }
            }
        }

        return null;
    }
}
