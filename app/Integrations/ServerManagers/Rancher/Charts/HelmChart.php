<?php

namespace App\Integrations\ServerManagers\Rancher\Charts;

use App\Support\Facades\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class HelmChart extends Chart
{
    public $app_storage;

    public $delete_method = 'remove';

    public function chartName()
    {
        if ($this->name === $this->app_instance->application->name) {
            return $this->format($this->app_instance->setOverrideIfEmpty('chart.name', $this->name));
        } else {
            return $this->format($this->app_instance->setOverrideIfEmpty("chart.{$this->chart_name}.name", $this->name));
        }
    }

    public function buildChart()
    {
        $app_instance = Application::instance($this->app_instance);
        $values = $this->values();
        $additionalConfigs = $this->app_instance->plan->additionalConfigs();

        foreach ($additionalConfigs as $config) {
            $key = str_replace('-', '.', $config['name']);
            Arr::set($values, "charts.0.values.$key", $app_instance->configuration($config['name']));
        }

        $chart = [
            'charts' => [
                [
                    'chartName' => $app_instance->version->setting('chart_name'), // The generic chart name in the repo
                    'version' => $app_instance->version->setting('chart_version'),
                    'releaseName' => $this->chartName(), // The chart name of this specific instance
                    'values' => $values,
                ],
            ],
            'noHooks' => false,
            'timeout' => '720s',
            'wait' => true,
            'namespace' => $this->organization->slug,
            'projectId' => null,
            'disableOpenAPIValidation' => false,
            'skipCRDs' => false,
        ];

        return $chart;
    }

    public function appStorage()
    {
        if (is_int($this->app_storage)) {
            return $this->app_storage;
        }

        $storage = Application::instance($this->app_instance)->storage();
        $total_storage = (int) $storage->totalAppStorage();

        $chart_storage = (int) $this->app_instance->setting('chart_storage');
        if (! $chart_storage || $chart_storage < $total_storage) {
            $this->app_instance->updateSetting('chart_storage', $total_storage);
            $chart_storage = $total_storage;
        }

        return $chart_storage > 0 ? $chart_storage : 1;
    }

    public function clusterIssuer()
    {
        return env('APP_ENV') == 'production' ? 'letsencrypt-production' : 'letsencrypt-staging';
    }

    public function appEnabled()
    {
        return $this->organization->status != 'deactivated' && $this->app_instance->status != 'deactivated';
    }

    public function replicaCount()
    {
        $expand_storage = $this->app_instance->setting('expand_storage');

        if ($expand_storage === true || ! $this->appEnabled()) {
            return 0;
        }

        return Application::instance($this->app_instance)->configuration('replicaCount') ?? 1;
    }

    public function format(string $string)
    {
        return Str::replace('_', '-', $string);
    }
}
