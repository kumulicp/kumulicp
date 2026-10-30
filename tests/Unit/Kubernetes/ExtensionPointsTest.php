<?php

// A driver layered on Kubernetes (e.g. Rancher) replaces only what differs:
// authentication and addressing, URL layout, how objects are written, how
// status is read off an object, and how a manifest is built. These tests
// extend the Kubernetes classes the way such a driver would, to pin down
// that each of those can be changed on its own.

use App\Integrations\ServerManagers\Kubernetes\API\Job;
use App\Integrations\ServerManagers\Kubernetes\API\KubernetesNamespace;
use App\Integrations\ServerManagers\Kubernetes\API\PersistentVolumeClaim;
use App\Integrations\ServerManagers\Kubernetes\Kubernetes;
use App\Integrations\ServerManagers\Kubernetes\Support\K8sCredentialContext;
use App\Integrations\ServerManagers\Kubernetes\Support\KubernetesApiClient;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

function extensionServer(): Server
{
    return Server::factory()->create([
        'interface' => 'kubernetes',
        'address' => 'https://proxy.example.com/k8s/clusters/local/',
        'api_key' => 'token-abc',
        'api_secret' => 'secret-xyz',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
    ]);
}

function extensionOrgServer(): OrgServer
{
    return OrgServer::create([
        'organization_id' => Organization::factory()->create()->id,
        'server_id' => extensionServer()->id,
    ]);
}

// Basic auth with the key and secret pair, and every path under a prefix.
class BasicAuthCredentials extends K8sCredentialContext
{
    protected function baseUrl(): string
    {
        return parent::baseUrl().'/prefix';
    }

    protected function authenticate(PendingRequest $http): void
    {
        $http->withBasicAuth($this->server->api_key, $this->server->api_secret);
    }
}

// A flat, group-dotted layout, creating by POSTing to a namespace-less collection and replacing with PUT.
class FlatLayoutClient extends KubernetesApiClient
{
    protected function path(array $resource, ?string $namespace, ?string $name = null): string
    {
        return '/v1/'.$resource[1].($namespace ? "/{$namespace}" : '').($name ? "/{$name}" : '');
    }

    protected function createPath(array $resource, ?string $namespace): string
    {
        return '/v1/'.$resource[1];
    }

    protected function updateObject(PendingRequest $http, array $resource, ?string $namespace, string $name, array $manifest, array $current): Response
    {
        return $http->put($this->path($resource, $namespace, $name), array_replace_recursive($current, $manifest));
    }
}

class ExtendedKubernetes extends Kubernetes
{
    protected function makeApiClient(): KubernetesApiClient
    {
        return new FlatLayoutClient($this->server(), new BasicAuthCredentials($this->server()));
    }
}

it('lets a subclass change the base URL and authentication without touching the client', function () {
    Http::fake(['*' => Http::response(['kind' => 'Status', 'message' => 'not found'], 404)]);

    $client = new KubernetesApiClient($server = extensionServer(), new BasicAuthCredentials($server));
    $client->get('v1', 'Namespace', 'demo');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://proxy.example.com/k8s/clusters/local/prefix/api/v1/namespaces/demo'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('token-abc:secret-xyz')));
});

function extensionConfigMap(): array
{
    return [
        'apiVersion' => 'v1',
        'kind' => 'ConfigMap',
        'metadata' => ['name' => 'settings', 'namespace' => 'demo'],
        'data' => ['key' => 'new'],
    ];
}

it('lets a subclass change the URL layout and where objects are created', function () {
    Http::fake(['*' => Http::response(['kind' => 'Status', 'message' => 'not found'], 404)]);

    (new FlatLayoutClient(extensionServer()))->apply(extensionConfigMap());

    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === 'https://proxy.example.com/k8s/clusters/local/v1/configmaps/demo/settings');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://proxy.example.com/k8s/clusters/local/v1/configmaps');
});

it('lets a subclass change how an existing object is updated', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response(['metadata' => ['name' => 'settings', 'resourceVersion' => '7'], 'data' => ['key' => 'old']])
        : Http::response(['replaced' => true]));

    (new FlatLayoutClient(extensionServer()))->apply(extensionConfigMap());

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://proxy.example.com/k8s/clusters/local/v1/configmaps/demo/settings'
        && $request['metadata']['resourceVersion'] === '7'
        && $request['data'] === ['key' => 'new']);
});

it('shares the overridden client with the objects it creates', function () {
    $org_server = extensionOrgServer();
    $driver = new ExtendedKubernetes($org_server->organization, $org_server);

    expect($driver->secret()->api())->toBe($driver->api());
    expect($driver->helmInstaller()->api())->toBe($driver->api());
    expect($driver->api())->toBeInstanceOf(FlatLayoutClient::class);
});

it('lets a subclass read status off its own object shape', function () {
    $org_server = extensionOrgServer();

    $namespace = new class($org_server->organization, $org_server) extends KubernetesNamespace
    {
        protected function state(array $namespace): int
        {
            return match ($namespace['metadata']['state']['name'] ?? null) {
                'active' => 1,
                'removing' => 2,
                default => 0,
            };
        }
    };

    Http::fake(['*' => Http::response(['metadata' => ['state' => ['name' => 'removing']]])]);

    expect($namespace->isActive())->toBe(2);
});

it('lets a subclass change the manifest it builds', function () {
    $org_server = extensionOrgServer();

    $namespace = new class($org_server->organization, $org_server) extends KubernetesNamespace
    {
        protected function manifest(string $namespace): array
        {
            return parent::manifest($namespace) + ['spec' => ['project' => 'p-1']];
        }
    };

    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response([], 404)
        : Http::response(['created' => true], 201));

    $namespace->create();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['spec'] === ['project' => 'p-1']);
});

it('lets a subclass pick the storage class without rebuilding the claim', function () {
    $org_server = extensionOrgServer();

    $claim = new class($org_server->organization, $org_server) extends PersistentVolumeClaim
    {
        protected function storageClass(): ?string
        {
            return 'longhorn';
        }
    };

    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response([], 404)
        : Http::response(['created' => true], 201));

    $claim->create('5Gi');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['spec']['storageClassName'] === 'longhorn'
        && $request['spec']['resources']['requests']['storage'] === '5Gi');
});

it('lets a subclass read a job state off its own object shape', function () {
    $org_server = extensionOrgServer();

    $job = new class($org_server->organization, $org_server) extends Job
    {
        protected function state(array $job): string
        {
            return ($job['metadata']['state']['error'] ?? false) ? 'failed' : 'success';
        }
    };

    Http::fake(['*' => Http::response(['metadata' => ['state' => ['error' => true]]])]);

    expect($job->status('demo-job'))->toBe('failed');
});
