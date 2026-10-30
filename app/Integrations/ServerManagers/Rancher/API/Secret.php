<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\Integrations\ServerManagers\Concerns\AppliesChartSecrets;
use App\Integrations\ServerManagers\Rancher\Rancher;
use App\RepoSecret;
use Illuminate\Support\Facades\Log;

class Secret extends Rancher
{
    use AppliesChartSecrets;

    // Check if the registry secret exists(1) or does not exist(0) in the given namespace
    public function isActive(string $namespace, RepoSecret $repo_secret): int
    {
        $address = $this->org_server->server->address;
        $name = $repo_secret->k8sSecretName();

        $url = $address.'/v1/secrets/'.$namespace.'/'.$name;
        $this->ignoreErrorCode(404)->get($url);
        $response = $this->response();

        if ($response['status_code'] === 404) {
            return 0;
        }

        return 1;
    }

    public function create(string $namespace, RepoSecret $repo_secret)
    {
        $address = $this->org_server->server->address;

        $url = $address.'/v1/secrets';

        $data = $this->values($namespace, $repo_secret);

        $this->json()->post($url, $data);

        Log::info(__('messages.api.rancher.log.secret_created', ['namespace' => $namespace, 'name' => $repo_secret->k8sSecretName()]), ['organization_id' => $this->organization->id]);

        if ($this->hasError()) {
            return [
                'status' => 'failed',
                'response' => $this->error(),
            ];
        }

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    public function remove(string $namespace, RepoSecret $repo_secret)
    {
        $address = $this->org_server->server->address;
        $name = $repo_secret->k8sSecretName();

        $url = $address.'/v1/secrets/'.$namespace.'/'.$name;

        $this->json()->ignoreErrorCode(404)->delete($url);

        Log::info(__('messages.api.rancher.log.secret_deleted', ['namespace' => $namespace, 'name' => $name]), ['organization_id' => $this->organization->id]);

        if ($this->hasError()) {
            return [
                'status' => 'failed',
                'response' => $this->error(),
            ];
        }

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    // Make sure the registry secret exists in the namespace, creating it if necessary
    public function ensure(string $namespace, RepoSecret $repo_secret)
    {
        if ($this->isActive($namespace, $repo_secret) === 1) {
            return [
                'status' => 'success',
                'response' => null,
            ];
        }

        return $this->create($namespace, $repo_secret);
    }

    private function values(string $namespace, RepoSecret $repo_secret): array
    {
        return [
            'kind' => 'Secret',
            'type' => 'kubernetes.io/dockerconfigjson',
            'metadata' => [
                'name' => $repo_secret->k8sSecretName(),
                'namespace' => $namespace,
                'labels' => $repo_secret->k8sLabels(),
            ],
            'data' => [
                '.dockerconfigjson' => base64_encode($repo_secret->dockerConfigJson()),
            ],
        ];
    }
}
