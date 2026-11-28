<?php

namespace App\Integrations\ServerManagers\Kubernetes\Charts\Job;

use App\Integrations\ServerManagers\Kubernetes\Charts\Chart;
use App\Support\Facades\Application;
use App\Support\Volumes\Volumes;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class JobChart extends Chart
{
    public $chart = [];

    public string $type = 'job';

    // One store per job instance: different jobs for the same app need
    // different keys, and re-applying a shared Secret would drop the other
    // job's keys. The Secret is owned by (and garbage-collected with) the Job.
    private ?string $secretsSuffix = null;

    protected function secretsName(): string
    {
        $this->secretsSuffix ??= Str::lower(Str::random(10));

        return Str::slug("{$this->organization->slug}-{$this->name}-job-{$this->secretsSuffix}-secrets");
    }

    /**
     * The claim a job mounts: the one that was chosen for the volume that holds the files, else what the
     * cluster says the release has. What the cluster says is not kept, so it is never taken for a choice.
     */
    public function persistentVolumeClaim()
    {
        $volumes = Volumes::for($this->app_instance);

        if ($volumes->has('data') && $claim = $volumes->get('data')->claim()) {
            return $claim;
        }

        $app = Application::instance($this->app_instance)->connect('web')->get();

        if (Arr::get($app, 'status') == 'success') {
            foreach (Arr::get($app, 'response.spec.resources', []) as $resource) {
                if (Arr::get($resource, 'kind') == 'PersistentVolumeClaim') {
                    return $resource['name'];
                }
            }
        }

        return null;
    }
}
