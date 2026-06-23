<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\Integrations\ServerManagers\Rancher\Rancher;
use Illuminate\Support\Facades\Log;

class Pod extends Rancher
{
    public function logsForJob(string $job_name): ?string
    {
        $namespace = $this->namespace();
        $address = $this->org_server->server->address;

        $pods_url = $address.'/v1/pods';
        $this->json()->get($pods_url, ['labelSelector' => "job-name={$job_name}"]);
        $response = $this->response();

        $pod_name = collect($response['content']['data'] ?? [])
            ->first()['metadata']['name'] ?? null;

        if (! $pod_name) {
            Log::warning(__('messages.api.rancher.log.job_created', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

            return null;
        }

        $logs_url = $address.'/api/v1/namespaces/'.$namespace.'/pods/'.$pod_name.'/log';

        $this->get($logs_url);

        return $this->response()['content'] ?? null;
    }
}
