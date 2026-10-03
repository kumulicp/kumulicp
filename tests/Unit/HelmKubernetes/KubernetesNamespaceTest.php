<?php

use App\Integrations\ServerManagers\HelmKubernetes\API\KubernetesNamespace;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeHelmK8sOrgServer(): OrgServer
{
    $server = Server::factory()->create([
        'interface' => 'helm_k8s',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
    ]);

    $organization = Organization::factory()->create();

    return OrgServer::create([
        'organization_id' => $organization->id,
        'server_id' => $server->id,
    ]);
}

it('maps an Active namespace phase to isActive() == 1', function () {
    $org_server = makeHelmK8sOrgServer();

    Http::fake([
        '*' => Http::response(['status' => ['phase' => 'Active']]),
    ]);

    $namespace = new KubernetesNamespace($org_server->organization, $org_server);

    expect($namespace->isActive())->toBe(1);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://cluster.example.com:6443/api/v1/namespaces/'.$org_server->organization->slug);
});

it('maps a Terminating namespace phase to isActive() == 2', function () {
    $org_server = makeHelmK8sOrgServer();

    Http::fake([
        '*' => Http::response(['status' => ['phase' => 'Terminating']]),
    ]);

    $namespace = new KubernetesNamespace($org_server->organization, $org_server);

    expect($namespace->isActive())->toBe(2);
});

it('maps a failed API call to isActive() == 0', function () {
    $org_server = makeHelmK8sOrgServer();

    Http::fake([
        '*' => Http::response(['kind' => 'Status', 'message' => 'namespaces "x" not found'], 404),
    ]);

    $namespace = new KubernetesNamespace($org_server->organization, $org_server);

    expect($namespace->isActive())->toBe(0);
});

it('creates the namespace as a cluster-scoped object', function () {
    $org_server = makeHelmK8sOrgServer();

    Http::fake(function (Request $request) {
        return $request->method() === 'GET'
            ? Http::response(['message' => 'not found'], 404)
            : Http::response(['metadata' => ['name' => 'created']], 201);
    });

    $namespace = new KubernetesNamespace($org_server->organization, $org_server);
    $result = $namespace->create();

    expect($result['status'])->toBe('success');
    expect($result['response']['metadata']['name'])->toBe('created');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://cluster.example.com:6443/api/v1/namespaces'
        && $request['kind'] === 'Namespace'
        && $request['metadata']['name'] === $org_server->organization->slug);
});

it('reports a failed namespace create with the API error message', function () {
    $org_server = makeHelmK8sOrgServer();

    Http::fake(function (Request $request) {
        return $request->method() === 'GET'
            ? Http::response(['message' => 'not found'], 404)
            : Http::response(['kind' => 'Status', 'message' => 'namespaces is forbidden'], 403);
    });

    $namespace = new KubernetesNamespace($org_server->organization, $org_server);
    $result = $namespace->create();

    expect($result)->toBe(['status' => 'failed', 'response' => 'namespaces is forbidden']);
});
