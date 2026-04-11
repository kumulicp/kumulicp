<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\Integrations\ServerManagers\Rancher\Rancher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class KubernetesNamespace extends Rancher
{
    public function create()
    {
        $namespace = $this->organization->slug;
        $address = $this->org_server->server->address;

        $url = $address.'/v1/namespaces';

        $data = $this->values($namespace);

        $this->json()->post($url, $data);
        $response = $this->response();

        Log::info(__('messages.api.rancher.log.namespace_created', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        if ($this->hasError()) {
            return [
                'status' => 'failed',
                'response' => $this->error(),
            ];
        }

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    public function update() {}

    public function remove()
    {
        $namespace = $this->organization->slug;
        $address = $this->org_server->server->address;

        $url = $address.'/v1/namespaces/'.$namespace;

        $data = $this->values($namespace);

        $this->json()->delete($url);
        $response = $this->response();

        Log::info(__('messages.api.rancher.log.namespace_deleted', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        if ($this->hasError()) {
            return [
                'status' => 'failed',
                'response' => $this->error(),
            ];
        }

        return [
            'status' => 'success',
            'response' => $this->response_content(),
        ];
    }

    // Check if the namespace is active(1), non existant (0), or transitioning (2)
    public function isActive(): int
    {
        $namespace = $this->organization->slug;
        $address = $this->org_server->server->address;

        // $url = '/v1';
        $url = $address.'/v1/namespaces/'.$namespace;
        $this->ignoreErrorCode(404)->get($url);
        $response = $this->response();

        // if the resource doesn't exist return false
        if (in_array($response['status_code'], [404, 403])) {
            return 0;
        }

        if (array_key_exists('content', $response) &&
            $response['content']) {
            // Check if namespace is transitioning;
            if (Arr::get($response, 'content.metadata.state.transitioning', false) === true) {
                return 2;
            }

            // Check if the namespace is active
            if ($response['content']['metadata']['state']['name'] === 'active') {
                return 1;
            }
        }

        // If no match, return non-existant
        return 0;
    }

    private function values(string $namespace): array
    {
        $project_id = $this->org_server->server->setting('project_id');

        return [
            'kind' => 'Namespace',
            'metadata' => [
                'name' => $namespace,
                'annotations' => [
                    'field.cattle.io/projectId' => 'local:'.$project_id,
                ],
                'labels' => [
                    'field.cattle.io/projectId' => $project_id,
                ],
            ],
            'disableOpenApiValidation' => false,
            'name' => $namespace,
        ];
    }
}
