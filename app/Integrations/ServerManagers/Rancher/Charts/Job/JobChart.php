<?php

namespace App\Integrations\ServerManagers\Rancher\Charts\Job;

use App\Integrations\ServerManagers\Rancher\Charts\Chart;
use App\Support\Facades\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class JobChart extends Chart
{
    public $chart = [];

    // One store per job instance: different jobs for the same app need
    // different keys, and re-applying a shared Secret would drop the other
    // job's keys. The Secret is owned by (and garbage-collected with) the Job.
    private ?string $secretsSuffix = null;

    protected function secretsName(): string
    {
        $this->secretsSuffix ??= Str::lower(Str::random(10));

        return Str::slug("{$this->organization->slug}-{$this->name}-job-{$this->secretsSuffix}-secrets");
    }

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
