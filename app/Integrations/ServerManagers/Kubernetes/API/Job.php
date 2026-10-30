<?php

namespace App\Integrations\ServerManagers\Kubernetes\API;

use App\Integrations\ServerManagers\Kubernetes\Kubernetes;
use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class Job extends Kubernetes
{
    public function create(JobChart $job)
    {
        $namespace = $this->namespace();

        // The Job's own manifest decides where it runs; its Secret must be there too.
        $job->secrets()->setNamespace(Arr::get($job->chart, 'metadata.namespace', $namespace));

        $secret_result = $this->secret()->applyStore($job->secrets());

        if ($secret_result['status'] !== 'success') {
            return ['status' => 'failed', 'response' => $secret_result['response']];
        }

        $result = $this->api()->apply($this->manifest($job), $namespace);

        if (! $job->secrets()->isEmpty()) {
            $this->settleJobSecret($job, $result);
        }

        Log::info(__('messages.api.rancher.log.job_created', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return [
            'status' => $result['success'] ? 'success' : 'failed',
            'response' => $result['success'] ? $result['data'] : $result['error'],
        ];
    }

    public function update(JobChart $job)
    {
        return $this->create($job);
    }

    public function remove(JobChart $job)
    {
        $namespace = $this->namespace();
        $result = $this->api()->delete('batch/v1', 'Job', $job->name, $namespace);

        Log::info(__('messages.api.rancher.log.job_deleted', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return ['status' => 'success', 'response' => $result['data']];
    }

    public function status(string $job_id): string
    {
        $namespace = $this->namespace();
        $result = $this->api()->get('batch/v1', 'Job', $job_id, $namespace);

        if (! $result['success']) {
            return 'failed';
        }

        return $this->state($result['data'] ?? []);
    }

    // Reads running, success or failed off the job object.
    protected function state(array $job): string
    {
        if (Arr::get($job, 'status.active') == 1) {
            return 'running';
        }

        if (Arr::get($job, 'status.succeeded') == 1) {
            return 'success';
        }

        if (Arr::get($job, 'status.failed') >= 1) {
            return 'failed';
        }

        return 'running';
    }

    // The Job to apply: the chart's own manifest, plus the pull secrets its image needs.
    protected function manifest(JobChart $job): array
    {
        $data = $job->chart;

        if ($pull_secrets = $job->imagePullSecrets()) {
            Arr::set($data, 'spec.template.spec.imagePullSecrets', array_map(fn ($name) => ['name' => $name], $pull_secrets));
        }

        return $data;
    }

    // A job's Secret is owned by the Job: gone with it on success, and removed
    // straight away if the Job was never created.
    private function settleJobSecret(JobChart $job, array $result): void
    {
        if (! $result['success']) {
            $this->secret()->removeStore($job->secrets());

            return;
        }

        // Re-apply with an ownerReference now the Job's uid is known.
        $uid = Arr::get($result['data'] ?? [], 'metadata.uid');

        if ($uid) {
            $this->secret()->applyStore($job->secrets(), ['name' => Arr::get($job->chart, 'metadata.name'), 'uid' => $uid]);
        }
    }
}
