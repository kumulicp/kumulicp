<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\API;

use App\Integrations\ServerManagers\HelmKubernetes\Kubernetes;
use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class Job extends Kubernetes
{
    public function create(JobChart $job)
    {
        $namespace = $this->namespace();
        $result = $this->kubectl()->apply($job->chart, $namespace);

        Log::info(__('messages.api.rancher.log.job_created', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return [
            'status' => $result['success'] ? 'success' : 'failed',
            'response' => $result['success'] ? json_decode($result['output'], true) : $result['error'],
        ];
    }

    public function update(JobChart $job)
    {
        return $this->create($job);
    }

    public function remove(JobChart $job)
    {
        $namespace = $this->namespace();
        $result = $this->kubectl()->delete('job', $job->name, $namespace);

        Log::info(__('messages.api.rancher.log.job_deleted', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return ['status' => 'success', 'response' => $result['output']];
    }

    public function status(string $job_id): string
    {
        $namespace = $this->namespace();
        $result = $this->kubectl()->get('job', $job_id, $namespace);

        if (! $result['success']) {
            return 'failed';
        }

        $data = json_decode($result['output'], true);

        if (Arr::get($data, 'status.active') == 1) {
            return 'running';
        }

        if (Arr::get($data, 'status.succeeded') == 1) {
            return 'success';
        }

        if (Arr::get($data, 'status.failed') >= 1) {
            return 'failed';
        }

        return 'running';
    }
}
