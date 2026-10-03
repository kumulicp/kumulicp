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
        $result = $this->kubectl()->apply($this->manifest($namespace), $namespace);

        Log::info(__('messages.api.rancher.log.namespace_created', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        if (! $result['success']) {
            return ['status' => 'failed', 'response' => $result['error']];
        }

        return ['status' => 'success', 'response' => json_decode($result['output'], true)];
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
        $namespace = $this->organization->slug;
        $result = $this->kubectl()->get('namespace', $namespace, $namespace);

        if (! $result['success']) {
            return ['error' => $result['error']];
        }

        $metadata = json_decode($result['output'], true)['metadata'] ?? [];

        return [
            'labels' => $metadata['labels'] ?? [],
            'annotations' => $metadata['annotations'] ?? [],
        ];
    }

    protected function applyNamespaceMetadata(array $changes): array
    {
        $namespace = $this->organization->slug;
        $patch = array_filter(['labels' => $changes['labels'], 'annotations' => $changes['annotations']]);
        $result = $this->kubectl()->mergePatch('namespace', $namespace, ['metadata' => $patch], $namespace);

        return ['success' => $result['success'], 'error' => $result['error']];
    }

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
