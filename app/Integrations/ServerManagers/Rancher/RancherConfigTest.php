<?php

namespace App\Integrations\ServerManagers\Rancher;

use App\Integrations\ServerManagers\Kubernetes\Support\KubernetesApiClient;
use App\Integrations\ServerManagers\Rancher\Support\RancherClusterCredentials;
use App\Server;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Creates a disposable namespace in the configured project, then a Secret in
// it through the cluster proxy, and deletes the namespace (and so the Secret)
// regardless of where it failed.
class RancherConfigTest
{
    private ?string $namespace = null;

    public function __construct(private Server $server) {}

    public function run(): array
    {
        if (! $this->server->address || ! $this->server->api_key || ! $this->server->api_secret) {
            return [
                'success' => false,
                'message' => __('admin.servers.rancher.test_missing_credentials'),
            ];
        }

        if (! $this->server->setting('project_id')) {
            return [
                'success' => false,
                'message' => __('admin.servers.rancher.test_missing_project'),
            ];
        }

        $name = 'kumuli-config-test-'.Str::lower(Str::random(8));

        try {
            $this->createNamespace($name);
            $this->createSecret($name);

            return [
                'success' => true,
                'message' => __('admin.servers.rancher.test_success'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('admin.servers.rancher.test_failed', ['reason' => $e->getMessage()]),
            ];
        } finally {
            $this->cleanup();
        }
    }

    private function client()
    {
        return Http::withBasicAuth($this->server->api_key, $this->server->api_secret)
            ->acceptJson()
            ->baseUrl(rtrim($this->server->address, '/'))
            ->timeout(15);
    }

    private function createNamespace(string $name): void
    {
        $project_id = $this->server->setting('project_id');

        // Same payload shape as the real KubernetesNamespace::create()
        $response = $this->client()->post('/v1/namespaces', [
            'kind' => 'Namespace',
            'metadata' => [
                'name' => $name,
                'annotations' => ['field.cattle.io/projectId' => 'local:'.$project_id],
                'labels' => ['field.cattle.io/projectId' => $project_id],
            ],
            'disableOpenApiValidation' => false,
            'name' => $name,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException(__('admin.servers.rancher.test_step.namespace').": HTTP {$response->status()} — ".$response->body());
        }

        $this->namespace = $name;
    }

    private function createSecret(string $namespace): void
    {
        $api = new KubernetesApiClient($this->server, new RancherClusterCredentials($this->server));

        $result = $api->apply([
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => $namespace, 'namespace' => $namespace],
            'type' => 'Opaque',
            'stringData' => ['test' => 'kumuli-config-test'],
        ], $namespace);

        if (! $result['success']) {
            throw new \RuntimeException(__('admin.servers.rancher.test_step.secret').": HTTP {$result['status']} — {$result['error']}");
        }
    }

    private function cleanup(): void
    {
        if ($this->namespace) {
            $this->client()->delete("/v1/namespaces/{$this->namespace}");
        }
    }
}
