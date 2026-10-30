<?php

namespace App\Integrations\ServerManagers\Kubernetes\API;

use App\Integrations\ServerManagers\Kubernetes\Kubernetes;
use App\Integrations\ServerManagers\Rancher\Charts\ChartSecrets;
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

    // Applies a chart's collected secrets as a standard Secret. Must run before
    // the workload that references it is created. No-op for an empty store.
    // $ownerJob (name, uid) is for job stores, so the Secret is garbage-collected
    // with its Job.
    public function applyStore(ChartSecrets $store, ?array $ownerJob = null): array
    {
        if ($store->isEmpty()) {
            return ['status' => 'success', 'response' => null];
        }

        $manifest = $store->manifest($ownerJob['uid'] ?? null, $ownerJob['name'] ?? null);
        $result = $this->api()->apply($manifest, $store->namespace());

        // Only the name is logged: the manifest and its values must never reach logs.
        Log::info(__('messages.api.rancher.log.chart_secret_applied', ['namespace' => $store->namespace(), 'name' => $store->name()]), ['organization_id' => $this->organization->id]);

        return [
            'status' => $result['success'] ? 'success' : 'failed',
            'response' => $result['success'] ? null : $result['error'],
        ];
    }

    public function removeStore(ChartSecrets $store): array
    {
        $result = $this->api()->delete('v1', 'Secret', $store->name(), $store->namespace());

        Log::info(__('messages.api.rancher.log.chart_secret_deleted', ['namespace' => $store->namespace(), 'name' => $store->name()]), ['organization_id' => $this->organization->id]);

        return ['status' => $result['success'] ? 'success' : 'failed', 'response' => $result['success'] ? null : $result['error']];
    }

    public function ensure(string $namespace, RepoSecret $repo_secret)
    {
        if ($this->isActive($namespace, $repo_secret) === 1) {
            return ['status' => 'success', 'response' => null];
        }

        return $this->create($namespace, $repo_secret);
    }

    protected function manifest(string $namespace, RepoSecret $repo_secret): array
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
