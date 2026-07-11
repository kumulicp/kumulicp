<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\API;

use App\Integrations\ServerManagers\HelmKubernetes\Kubernetes;
use Illuminate\Support\Facades\Log;

class PersistentVolumeClaim extends Kubernetes
{
    public function create(string $claim_size = '20Gi')
    {
        $namespace = $this->organization->slug;
        $claim_string = $namespace.'-pvc';

        $result = $this->kubectl()->apply($this->manifest($namespace, $claim_string, $claim_size), $namespace);

        Log::info(__('messages.api.rancher.log.persistent_volume_claim_created', ['organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        return [
            'status' => $result['success'] ? 'success' : 'failed',
            'response' => $result['success'] ? json_decode($result['output'], true) : $result['error'],
        ];
    }

    public function update() {}

    public function isActive(): int
    {
        $namespace = $this->organization->slug;
        $result = $this->kubectl()->get('persistentvolumeclaim', $namespace.'-pvc', $namespace);

        if (! $result['success']) {
            return 0;
        }

        $data = json_decode($result['output'], true);
        $phase = $data['status']['phase'] ?? null;

        return match ($phase) {
            'Bound' => 1,
            'Pending' => 2,
            default => 0,
        };
    }

    // No storage class is hardcoded (unlike Rancher's Longhorn default) so this
    // works on any cluster; set the server setting 'storage_class' to override
    // the cluster's default StorageClass.
    private function manifest(string $namespace, string $claim_string, string $claim_size): array
    {
        $spec = [
            'accessModes' => ['ReadWriteOnce'],
            'resources' => [
                'requests' => [
                    'storage' => $claim_size,
                ],
            ],
        ];

        if ($storage_class = $this->server()->setting('storage_class')) {
            $spec['storageClassName'] = $storage_class;
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'PersistentVolumeClaim',
            'metadata' => [
                'namespace' => $namespace,
                'name' => $claim_string,
            ],
            'spec' => $spec,
        ];
    }
}
