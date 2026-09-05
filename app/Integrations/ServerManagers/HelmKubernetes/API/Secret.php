<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\API;

use App\Integrations\ServerManagers\HelmKubernetes\Kubernetes;
use App\RepoSecret;
use Illuminate\Support\Facades\Log;

class Secret extends Kubernetes
{
    public function isActive(string $namespace, RepoSecret $pull_secret): int
    {
        $result = $this->kubectl()->get('secret', $pull_secret->k8sSecretName(), $namespace);

        return $result['success'] ? 1 : 0;
    }

    public function create(string $namespace, RepoSecret $pull_secret)
    {
        $result = $this->kubectl()->apply($this->manifest($namespace, $pull_secret), $namespace);

        Log::info(__('messages.api.rancher.log.secret_created', ['namespace' => $namespace, 'name' => $pull_secret->k8sSecretName()]), ['organization_id' => $this->organization->id]);

        return [
            'status' => $result['success'] ? 'success' : 'failed',
            'response' => $result['success'] ? json_decode($result['output'], true) : $result['error'],
        ];
    }

    public function remove(string $namespace, RepoSecret $pull_secret)
    {
        $result = $this->kubectl()->delete('secret', $pull_secret->k8sSecretName(), $namespace);

        Log::info(__('messages.api.rancher.log.secret_deleted', ['namespace' => $namespace, 'name' => $pull_secret->k8sSecretName()]), ['organization_id' => $this->organization->id]);

        return ['status' => 'success', 'response' => $result['output']];
    }

    public function ensure(string $namespace, RepoSecret $pull_secret)
    {
        if ($this->isActive($namespace, $pull_secret) === 1) {
            return ['status' => 'success', 'response' => null];
        }

        return $this->create($namespace, $pull_secret);
    }

    private function manifest(string $namespace, RepoSecret $pull_secret): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'type' => 'kubernetes.io/dockerconfigjson',
            'metadata' => [
                'name' => $pull_secret->k8sSecretName(),
                'namespace' => $namespace,
            ],
            'data' => [
                '.dockerconfigjson' => base64_encode($pull_secret->dockerConfigJson()),
            ],
        ];
    }
}
