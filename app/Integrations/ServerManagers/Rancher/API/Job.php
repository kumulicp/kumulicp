<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;
use App\Integrations\ServerManagers\Rancher\Rancher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class Job extends Rancher
{
    public function create(JobChart $job)
    {
        $namespace = $this->namespace();
        $address = $this->org_server->server->address;

        $url = $address.'/v1/batch.jobs';

        $data = $job->chart;

        if ($pull_secrets = $job->imagePullSecrets()) {
            Arr::set($data, 'spec.template.spec.imagePullSecrets', array_map(fn ($name) => ['name' => $name], $pull_secrets));
        }

        // The Job's own manifest decides where it runs; its Secret must be there too.
        $job->secrets()->setNamespace(Arr::get($job->chart, 'metadata.namespace', $namespace));

        $secret_result = $this->secret()->applyStore($job->secrets());

        if ($secret_result['status'] !== 'success') {
            return ['status' => 'failed', 'response' => $secret_result['response']];
        }

        try {
            $this->json()->post($url, $data);
        } catch (\Throwable $e) {
            $this->secret()->removeStore($job->secrets());

            throw $e;
        }

        $response = $this->response();

        Log::info(__('messages.api.rancher.log.job_created', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        $content = $this->response_content();

        if (! $job->secrets()->isEmpty()) {
            $this->settleJobSecret($job, $content);
        }

        return [
            'status' => 'success',
            'response' => $content,
        ];
    }

    // A job's Secret is owned by the Job: gone with it on success, and removed
    // once the Job is confirmed not to exist. The Job's uid comes from the
    // create response, else from the cluster; if that lookup fails for any
    // other reason the Secret is left alone, since removing it would break a
    // Job that did start.
    private function settleJobSecret(JobChart $job, mixed $created): void
    {
        $name = Arr::get($job->chart, 'metadata.name');
        $uid = Arr::get(is_array($created) ? $created : [], 'metadata.uid');

        if (! $uid) {
            $found = $this->api()->get('batch/v1', 'Job', $name, $job->secrets()->namespace());
            $uid = Arr::get($found['data'] ?? [], 'metadata.uid');

            if (! $uid) {
                if ($found['status'] === 404) {
                    $this->secret()->removeStore($job->secrets());
                }

                return;
            }
        }

        // Re-apply with an ownerReference now the Job's uid is known.
        $this->secret()->applyStore($job->secrets(), ['name' => $name, 'uid' => $uid]);
    }

    // primarily for adding regex redirects
    public function update(JobChart $job)
    {
        $namespace = $this->namespace();
        $address = $this->org_server->server->address;

        $url = $address.'/v1/batch.jobs/'.$namespace.'/'.$job->name;

        $this->get($url);
        $response = $this->response();

        if ($response['status_code'] != 200) {
            return $this->create($job);
        }

        $data = $response['content'];

        $data['spec'] = $job->chart['spec'];

        $this->json()->put($url, $data);
        $response = $this->response();

        Log::info(__('messages.api.rancher.log.job_updated', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    public function remove(JobChart $job)
    {
        $namespace = $this->namespace();
        $address = $this->org_server->server->address;

        $url = $address.'/v1/batch.jobs/'.$namespace.'/'.$job->name;

        $this->json()->delete($url);
        $response = $this->response();

        Log::info(__('messages.api.rancher.log.job_deleted', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    // Check if the middleware is active(1), non existant (0), or transitioning (2)
    public function status(string $job_id): string
    {
        $namespace = $this->namespace();
        $address = $this->org_server->server->address;

        $url = $address.'/v1/batch.jobs/'.$namespace.'/'.$job_id;
        $this->ignoreErrorCode(404)->get($url);
        $response = $this->response();

        // if the resource doesn't exist return false
        if ($response['status_code'] === 404) {
            if ($message = Arr::get($response, 'content.message')) {
                $this->setError($message, '404');
            } else {
                $this->setError('Page doesn\'t exist', '404');
            }
        }

        if (Arr::get($response, 'content.metadata.state.error') === true) {
            $this->setError(__('messages.api.rancher.error.job', ['job' => $job_id, 'message' => Arr::get($response, 'content.metadata.state.message')]), 'job_fail');

            return 'failed';
        } elseif (Arr::get($response, 'content.status.active') == 1) {
            return 'running';
        }
        // Check if the namespace is active
        elseif (Arr::get($response, 'content.status.succeeded') == 1) {
            return 'success';
        }

        // If no match, return non-existant
        return 'failed';
    }
}
