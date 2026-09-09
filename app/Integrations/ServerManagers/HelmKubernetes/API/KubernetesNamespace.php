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
}
