<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\API;

use App\Integrations\ServerManagers\HelmKubernetes\Kubernetes;
use App\RepoSecret;
use Illuminate\Support\Facades\Log;

class Secret extends Kubernetes
{
    public function isActive(string $namespace, RepoSecret $repo_secret): int
    {
<<<<<<< HEAD
        $result = $this->kubectl()->get('secret', $repo_secret->k8sSecretName(), $namespace);
=======
        $result = $this->api()->get('v1', 'Secret', $repo_secret->k8sSecretName(), $namespace);
>>>>>>> 5b51a30e (Replace kubectl/helm CLI calls with direct Kubernetes API calls)

        return $result['success'] ? 1 : 0;
    }

    public function create(string $namespace, RepoSecret $repo_secret)
    {
<<<<<<< HEAD
        $result = $this->kubectl()->apply($this->manifest($namespace, $repo_secret), $namespace);
=======
        $result = $this->api()->apply($this->manifest($namespace, $repo_secret), $namespace);
>>>>>>> 5b51a30e (Replace kubectl/helm CLI calls with direct Kubernetes API calls)

        Log::info(__('messages.api.rancher.log.secret_created', ['namespace' => $namespace, 'name' => $repo_secret->k8sSecretName()]), ['organization_id' => $this->organization->id]);

        return [
            'status' => $result['success'] ? 'success' : 'failed',
            'response' => $result['success'] ? $result['data'] : $result['error'],
        ];
    }

    public function remove(string $namespace, RepoSecret $repo_secret)
    {
<<<<<<< HEAD
        $result = $this->kubectl()->delete('secret', $repo_secret->k8sSecretName(), $namespace);
=======
        $result = $this->api()->delete('v1', 'Secret', $repo_secret->k8sSecretName(), $namespace);
>>>>>>> 5b51a30e (Replace kubectl/helm CLI calls with direct Kubernetes API calls)

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
            ],
            'data' => [
                '.dockerconfigjson' => base64_encode($repo_secret->dockerConfigJson()),
            ],
        ];
    }
}
