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
     * The claim a job mounts: the one that was chosen for the app's volume, else the one that was
     * found on the cluster before. A claim found now is kept apart from a chosen one
     * (`discovered`), so the release is never given it as a claim that exists.
     */
    public function persistentVolumeClaim()
    {
        $volumes = Volumes::for($this->app_instance);

        if ($claim = $volumes->claimName('app', 'data')) {
            return $claim;
        }

        // Where an app kept it before volumes were kept in one place, whatever its chart
        if ($pvc_name = $this->app_instance->getOverride('pvc.name')) {
            return $pvc_name;
        }

        $app = Application::instance($this->app_instance)->connect('web')->get();

        if (Arr::get($app, 'status') == 'success') {
            foreach (Arr::get($app, 'response.spec.resources', []) as $resource) {
                if (Arr::get($resource, 'kind') == 'PersistentVolumeClaim') {
                    $volumes->assign('app', 'data', ['discovered' => $resource['name']]);

                    return $resource['name'];
                }
            }
        }

        return null;
    }
}
