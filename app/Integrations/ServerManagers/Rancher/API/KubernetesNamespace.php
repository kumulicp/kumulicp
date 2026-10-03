<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\Integrations\ServerManagers\Rancher\Rancher;
use App\Support\Security\Concerns\ReconcilesNamespaceSecurity;
use App\Support\Security\NamespaceSecurityReconciler;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class KubernetesNamespace extends Rancher
{
    use ReconcilesNamespaceSecurity;

    // The namespace as last read, kept so applyNamespaceMetadata() can PUT it back
    private ?array $current_object = null;

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

    // Brings the namespace's Pod Security labels in line with its plan's
    // tier. Does nothing (and makes no Rancher calls) unless the server's
    // security_mode is `managed`.
    public function update()
    {
        return $this->reconcileSecurity();
    }

    protected function currentNamespaceMetadata(): array
    {
        $url = $this->org_server->server->address.'/v1/namespaces/'.$this->organization->slug;

        $this->ignoreErrorCode(404)->get($url);
        $response = $this->response();

        if (in_array($response['status_code'], [404, 403]) || ! is_array($response['content'] ?? null)) {
            return ['error' => Arr::get($response, 'error.message') ?: "Namespace not found ({$response['status_code']})"];
        }

        $this->current_object = $response['content'];

        return [
            'labels' => Arr::get($this->current_object, 'metadata.labels', []),
            'annotations' => Arr::get($this->current_object, 'metadata.annotations', []),
        ];
    }

    // Rancher's API replaces the whole resource on PUT, so write back the
    // object read by currentNamespaceMetadata() with only the changes applied.
    protected function applyNamespaceMetadata(array $changes): array
    {
        $object = $this->current_object;

        foreach (['labels', 'annotations'] as $kind) {
            $values = Arr::get($object, "metadata.$kind", []);

            foreach ($changes[$kind] as $key => $value) {
                if ($value === null) {
                    unset($values[$key]);
                } else {
                    $values[$key] = $value;
                }
            }

            // An empty PHP array would encode as a JSON list, not a map
            Arr::set($object, "metadata.$kind", $values ?: (object) []);
        }

        $url = $this->org_server->server->address.'/v1/namespaces/'.$this->organization->slug;

        $this->json()->put($url, $object);

        if ($this->hasError()) {
            return ['success' => false, 'error' => (string) $this->error()];
        }

        return ['success' => true, 'error' => ''];
    }

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
        $security = NamespaceSecurityReconciler::metadataForCreate($this->desiredSecurity());

        return [
            'kind' => 'Namespace',
            'metadata' => [
                'name' => $namespace,
                'annotations' => [
                    'field.cattle.io/projectId' => 'local:'.$project_id,
                ] + $security['annotations'],
                'labels' => [
                    'field.cattle.io/projectId' => $project_id,
                ] + $security['labels'],
            ],
            'disableOpenApiValidation' => false,
            'name' => $namespace,
        ];
    }
}
