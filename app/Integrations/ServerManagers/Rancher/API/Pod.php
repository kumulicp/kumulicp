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

        $pods_url = $address.'/v1/pods/'.$namespace;
        $this->json()->get($pods_url, ['labelSelector' => "job-name={$job_name}"]);
        $response = $this->response();

        $pod_name = collect($response['content']['data'] ?? [])
            ->first(fn ($pod) => ($pod['metadata']['labels']['job-name'] ?? null) === $job_name)['metadata']['name'] ?? null;

        if (! $pod_name) {
            Log::warning(__('messages.api.rancher.log.job_created', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

            return null;
        }

        $logs_url = $address.'/api/v1/namespaces/'.$namespace.'/pods/'.$pod_name.'/log';

        // Integration's default 10s timeout is fine for typical API calls but
        // nowhere near enough to pull a large scan report's worth of pod
        // logs (seen timing out past 28MB received on a real trivy scan).
        $this->timeout = 120.0;
        $this->raw()->ignoreErrorCode(404)->get($logs_url);
        $response = $this->response();

        if ($response['status_code'] === 404) {
            Log::warning(__('messages.api.rancher.log.job_created', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

            return null;
        }

        return $response['content'] ?? null;
    }
}
