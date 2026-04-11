<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\Integrations\ServerManagers\Rancher\Rancher;
use Illuminate\Support\Facades\Log;

class PersistentVolumeClaim extends Rancher
{
    public function create(string $claim_size = '20Gi')
    {
        $namespace = $this->organization->slug;
        $url = '/v1/persistentvolumeclaims';

        $claim_string = $namespace.'-pvc';

        // No existing pvc, create a new one
        $data = $this->values($namespace, $claim_string, $claim_size);

        $response = $this->json()->post($url, $data);

        Log::info(__('messages.api.rancher.log.persistent_volume_claim_created', ['organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    public function update() {}

    // public function delete()
    // {}

    public function isActive(): int
    {
        $namespace = $this->organization->slug;
        $url = '/v1/persistentvolumeclaims/'.$namespace.'/'.$namespace.'-pvc';
        $this->get($url);
        $response = $this->response();

        // if the resource doesn't exist return false
        if ($response['status_code'] === 404) {
            return 0;
        }

        // Check if persistent volume claim is transitioning;
        if ($response['content']['metadata']['state']['transitioning'] === true) {
            return 2;
        }

        // Check if the persistent volume claim is active
        if ($response['content']['metadata']['state']['name'] === 'bound') {
            return 1;
        }

        // If no match, return non-existant
        return 0;
    }

    private function values(string $namespace, string $claim_string, string $claim_size = '20Gi'): array
    {
        return [
            'type' => 'persistentvolumeclaim',
            'metadata' => [
                'namespace' => $namespace,
                'name' => $claim_string,
            ],
            'spec' => [
                'accessModes' => [
                    'ReadWriteOnce',
                ],
                'storageClassName' => 'longhorn',
                'volumeName' => '',
                'resources' => [
                    'requests' => [
                        'storage' => $claim_size,
                    ],
                ],
            ],
        ];
    }
}
