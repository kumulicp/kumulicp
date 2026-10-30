<?php

namespace App\Integrations\ServerManagers\Kubernetes\API;

use App\Integrations\ServerManagers\Kubernetes\Kubernetes;
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

        return $this->state($result['data'] ?? []);
    }

    // Reads active(1), non existant (0), or transitioning (2) off the namespace object.
    protected function state(array $namespace): int
    {
        return match ($namespace['status']['phase'] ?? null) {
            'Active' => 1,
            'Terminating' => 2,
            default => 0,
        };
    }

    protected function manifest(string $namespace): array
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
