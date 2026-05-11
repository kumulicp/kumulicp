<?php

namespace Tests\Support\Applications;

use App\Integrations\ServerManagers\Rancher\Charts\HelmChart;
use App\Support\Facades\Application;

class DemoHelmChart extends HelmChart
{
    public $chart_name = 'demo';

    public function values(): array
    {
        $app_instance = Application::instance($this->app_instance);

        return [
            'persistentValue' => $app_instance->configuration('persistent-value'),
            'nonPersistentValue' => $app_instance->configuration('non-persistent-value'),
            'overrideValue' => $app_instance->configuration('override-value'),
        ];
    }
}
