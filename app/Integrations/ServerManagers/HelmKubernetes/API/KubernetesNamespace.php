<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\API;

use App\Integrations\ServerManagers\HelmKubernetes\Kubernetes;
use Illuminate\Support\Facades\Log;

class KubernetesNamespace extends Kubernetes
{
    public function create()
    {
        $namespace = $this->organization->slug;
        $result = $this->kubectl()->apply($this->manifest($namespace), $namespace);

        Log::info(__('messages.api.rancher.log.namespace_created', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        if (! $result['success']) {
            return ['status' => 'failed', 'response' => $result['error']];
        }

        // Per-namespace identity the in-cluster helm install Job runs under
        // (see HelmInstaller) -- broad permissions, but scoped to just this
        // namespace via the RoleBinding, unlike kumulicp-deployer's own
        // ClusterRole. A namespace without this can't run Job-mode installs
        // at all, so treat either apply failing as fatal to create().
        $sa_result = $this->kubectl()->apply($this->serviceAccountManifest($namespace), $namespace);
        if (! $sa_result['success']) {
            return ['status' => 'failed', 'response' => $sa_result['error']];
        }

        $binding_result = $this->kubectl()->apply($this->roleBindingManifest($namespace), $namespace);
        if (! $binding_result['success']) {
            return ['status' => 'failed', 'response' => $binding_result['error']];
        }

        return ['status' => 'success', 'response' => json_decode($result['output'], true)];
    }

    public function update() {}

    public function remove()
    {
        $namespace = $this->organization->slug;
        $result = $this->kubectl()->delete('namespace', $namespace, $namespace);

        Log::info(__('messages.api.rancher.log.namespace_deleted', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        if (! $result['success']) {
            return ['status' => 'failed', 'response' => $result['error']];
        }

        return ['status' => 'success', 'response' => json_decode($result['output'], true)];
    }

    // Check if the namespace is active(1), non existant (0), or transitioning (2)
    public function isActive(): int
    {
        $namespace = $this->organization->slug;
        $result = $this->kubectl()->get('namespace', $namespace, $namespace);

        if (! $result['success']) {
            return 0;
        }

        $data = json_decode($result['output'], true);
        $phase = $data['status']['phase'] ?? null;

        if ($phase === 'Active') {
            return 1;
        }

        if ($phase === 'Terminating') {
            return 2;
        }

        return 0;
    }

    private function manifest(string $namespace): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => [
                'name' => $namespace,
            ],
        ];
    }

    private function serviceAccountManifest(string $namespace): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'ServiceAccount',
            'metadata' => [
                'name' => 'kumulicp-helm-installer',
                'namespace' => $namespace,
            ],
        ];
    }

    // References the cluster-scoped kumulicp-helm-installer ClusterRole
    // (see docs/k8s-rbac-sample.yaml), but this RoleBinding is itself
    // namespaced -- the standard way to scope one ClusterRole's rules to a
    // single namespace without duplicating a Role definition per org.
    private function roleBindingManifest(string $namespace): array
    {
        return [
            'apiVersion' => 'rbac.authorization.k8s.io/v1',
            'kind' => 'RoleBinding',
            'metadata' => [
                'name' => 'kumulicp-helm-installer',
                'namespace' => $namespace,
            ],
            'subjects' => [[
                'kind' => 'ServiceAccount',
                'name' => 'kumulicp-helm-installer',
                'namespace' => $namespace,
            ]],
            'roleRef' => [
                'kind' => 'ClusterRole',
                'name' => 'kumulicp-helm-installer',
                'apiGroup' => 'rbac.authorization.k8s.io',
            ],
        ];
    }
}
