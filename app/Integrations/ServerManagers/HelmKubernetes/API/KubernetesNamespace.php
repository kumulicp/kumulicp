<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\API;

use App\Integrations\ServerManagers\HelmKubernetes\Kubernetes;
use App\Support\Security\Concerns\ReconcilesNamespaceSecurity;
use App\Support\Security\NamespaceSecurityReconciler;
use Illuminate\Support\Facades\Log;

class KubernetesNamespace extends Kubernetes
{
    use ReconcilesNamespaceSecurity;

    public function create()
    {
        $namespace = $this->organization->slug;
        $result = $this->api()->apply($this->manifest($namespace));

        Log::info(__('messages.api.rancher.log.namespace_created', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        if (! $result['success']) {
            return ['status' => 'failed', 'response' => $result['error']];
        }

        return ['status' => 'success', 'response' => $result['data']];
    }

    // Brings the namespace's Pod Security labels in line with its plan's
    // tier. Does nothing (and makes no cluster calls) unless the server's
    // security_mode is `managed`.
    public function update()
    {
        return $this->reconcileSecurity();
    }

    protected function currentNamespaceMetadata(): array
    {
        $result = $this->api()->get('v1', 'Namespace', $this->organization->slug);

        if (! $result['success']) {
            return ['error' => $result['error']];
        }

        return [
            'labels' => $result['data']['metadata']['labels'] ?? [],
            'annotations' => $result['data']['metadata']['annotations'] ?? [],
        ];
    }

    // apply() is a JSON merge patch for an object that already exists, so a
    // null value removes that label/annotation. That only needs get + patch
    // on namespaces, the same verbs apply() always did.
    protected function applyNamespaceMetadata(array $changes): array
    {
        $result = $this->api()->apply([
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => array_filter([
                'name' => $this->organization->slug,
                'labels' => $changes['labels'],
                'annotations' => $changes['annotations'],
            ]),
        ]);

        return ['success' => $result['success'], 'error' => $result['error']];
    }

    public function remove()
    {
        $namespace = $this->organization->slug;
        $result = $this->api()->delete('v1', 'Namespace', $namespace);

        Log::info(__('messages.api.rancher.log.namespace_deleted', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        if (! $result['success']) {
            return ['status' => 'failed', 'response' => $result['error']];
        }

        return ['status' => 'success', 'response' => $result['data']];
    }

    // Check if the namespace is active(1), non existant (0), or transitioning (2)
    public function isActive(): int
    {
        $namespace = $this->organization->slug;
        $result = $this->api()->get('v1', 'Namespace', $namespace);

        if (! $result['success']) {
            return 0;
        }

        $data = $result['data'];
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
        $security = NamespaceSecurityReconciler::metadataForCreate($this->desiredSecurity());

        return [
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => array_filter([
                'name' => $namespace,
                'labels' => $security['labels'],
                'annotations' => $security['annotations'],
            ]),
        ];
    }
}
