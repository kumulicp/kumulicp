<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\API;

use App\Integrations\ServerManagers\HelmKubernetes\Kubernetes;
use Illuminate\Support\Facades\Log;

class KubernetesNamespace extends Kubernetes
{
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

    public function update() {}

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
        return [
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => [
                'name' => $namespace,
            ],
        ];
    }
}
