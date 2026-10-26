<?php

namespace App\Integrations\ServerManagers\Kubernetes\API;

use App\Integrations\ServerManagers\Kubernetes\Kubernetes;
use App\RepoSecret;
use Illuminate\Support\Facades\Log;

class Secret extends Kubernetes
{
    public function isActive(string $namespace, RepoSecret $repo_secret): int
    {
        $result = $this->api()->get('v1', 'Secret', $repo_secret->k8sSecretName(), $namespace);

        return $result['success'] ? 1 : 0;
    }

    public function create(string $namespace, RepoSecret $repo_secret)
    {
        $result = $this->api()->apply($this->manifest($namespace, $repo_secret), $namespace);

        Log::info(__('messages.api.rancher.log.secret_created', ['namespace' => $namespace, 'name' => $repo_secret->k8sSecretName()]), ['organization_id' => $this->organization->id]);

        return [
            'status' => $result['success'] ? 'success' : 'failed',
            'response' => $result['success'] ? $result['data'] : $result['error'],
        ];
    }

    public function remove(string $namespace, RepoSecret $repo_secret)
    {
        $result = $this->api()->delete('v1', 'Secret', $repo_secret->k8sSecretName(), $namespace);

        Log::info(__('messages.api.rancher.log.secret_deleted', ['namespace' => $namespace, 'name' => $repo_secret->k8sSecretName()]), ['organization_id' => $this->organization->id]);

        return ['status' => 'success', 'response' => $result['data']];
    }

    public function ensure(string $namespace, RepoSecret $repo_secret)
    {
        if ($this->isActive($namespace, $repo_secret) === 1) {
            return ['status' => 'success', 'response' => null];
        }

        return $this->create($namespace, $repo_secret);
    }

    private function manifest(string $namespace, RepoSecret $repo_secret): array
    {
        return [
            'apiVersion' => 'v1',
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
