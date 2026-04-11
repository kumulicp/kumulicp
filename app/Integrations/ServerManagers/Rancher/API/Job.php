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

        $this->json()->post($url, $data);
        $response = $this->response();

        Log::info(__('messages.api.rancher.log.job_created', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
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

        $data['spec'] = $job->values()['spec'];

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

        $url = $address.'/v1/batch.jobs/'.$namespace.'/'.$chart->name;

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

        if ($errors = Arr::get($response, 'content.metadata.state.name') == 'failed') {
            return $this->setError(__('messages.api.rancher.error.job', ['job' => $job_id, 'message' => Arr::get($response, 'content.status.conditions.0.message')]), 'job_fail');
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
