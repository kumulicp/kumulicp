<?php

use App\Integrations\ServerManagers\Rancher\API\KubernetesNamespace;
use App\Organization;
use App\OrgServer;
use App\Plan;
use App\Server;
use App\Support\Security\NamespaceSecurityReconciler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeRancherSecuredOrgServer(?string $mode, ?string $tier): OrgServer
{
    $server = Server::factory()->create([
        'interface' => 'rancher',
        'address' => 'https://rancher.example.test',
        'host' => 'https://rancher.example.test',
        'settings' => array_filter(['project_id' => 'p-123', 'security_mode' => $mode]),
    ]);

    $plan = Plan::factory()->create(['settings' => $tier ? ['security' => ['tier' => $tier]] : []]);
    $organization = Organization::factory()->create(['plan_id' => $plan->id]);

    return OrgServer::create(['organization_id' => $organization->id, 'server_id' => $server->id]);
}

function rancherNamespaceObject(string $slug, array $labels = [], array $annotations = []): array
{
    return [
        'id' => $slug,
        'type' => 'namespace',
        'metadata' => ['name' => $slug, 'resourceVersion' => '7', 'labels' => $labels, 'annotations' => $annotations],
    ];
}

it('creates the namespace with only the project metadata by default', function () {
    $org_server = makeRancherSecuredOrgServer(null, 'restricted');
    Http::fake(['https://rancher.example.test/*' => Http::response(['id' => 'ok'], 201)]);

    (new KubernetesNamespace($org_server->organization, $org_server))->create();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['metadata']['labels'] === ['field.cattle.io/projectId' => 'p-123']
        && $request['metadata']['annotations'] === ['field.cattle.io/projectId' => 'local:p-123']);
});

it('adds the plan\'s pod security labels at creation when managed', function () {
    $org_server = makeRancherSecuredOrgServer('managed', 'observe');
    Http::fake(['https://rancher.example.test/*' => Http::response(['id' => 'ok'], 201)]);

    (new KubernetesNamespace($org_server->organization, $org_server))->create();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['metadata']['labels']['field.cattle.io/projectId'] === 'p-123'
        && $request['metadata']['labels']['pod-security.kubernetes.io/warn'] === 'restricted'
        && $request['metadata']['annotations'][NamespaceSecurityReconciler::ANNOTATION] === 'observe');
});

it('makes no Rancher calls when updating an unmanaged namespace', function () {
    $org_server = makeRancherSecuredOrgServer(null, 'restricted');
    Http::fake();

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->update();

    expect($result['action'])->toBe('unmanaged');
    Http::assertNothingSent();
});

it('writes the namespace back with the plan\'s labels added and the rest preserved', function () {
    $org_server = makeRancherSecuredOrgServer('managed', 'observe');
    $slug = $org_server->organization->slug;
    Http::fake(['https://rancher.example.test/v1/namespaces/*' => Http::sequence()
        ->push(rancherNamespaceObject($slug, ['field.cattle.io/projectId' => 'p-123']))
        ->push(['id' => $slug])]);

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->update();

    expect($result)->toMatchArray(['status' => 'success', 'action' => 'updated']);
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request['metadata']['resourceVersion'] === '7'
        && $request['metadata']['labels']['field.cattle.io/projectId'] === 'p-123'
        && $request['metadata']['labels']['pod-security.kubernetes.io/warn'] === 'restricted'
        && $request['metadata']['annotations'][NamespaceSecurityReconciler::ANNOTATION] === 'observe');
});

it('does not write a namespace that already matches', function () {
    $org_server = makeRancherSecuredOrgServer('managed', 'observe');
    $slug = $org_server->organization->slug;
    Http::fake(['https://rancher.example.test/v1/namespaces/*' => Http::response(rancherNamespaceObject(
        $slug,
        ['pod-security.kubernetes.io/warn' => 'restricted', 'pod-security.kubernetes.io/audit' => 'restricted'],
        [NamespaceSecurityReconciler::ANNOTATION => 'observe'],
    ))]);

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->update();

    expect($result['action'])->toBe('unchanged');
    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
});

it('removes only the labels it owns when the plan goes back to no tier', function () {
    $org_server = makeRancherSecuredOrgServer('managed', null);
    $slug = $org_server->organization->slug;
    Http::fake(['https://rancher.example.test/v1/namespaces/*' => Http::sequence()
        ->push(rancherNamespaceObject(
            $slug,
            ['pod-security.kubernetes.io/warn' => 'restricted', 'field.cattle.io/projectId' => 'p-123'],
            [NamespaceSecurityReconciler::ANNOTATION => 'observe'],
        ))
        ->push(['id' => $slug])]);

    (new KubernetesNamespace($org_server->organization, $org_server))->update();

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request['metadata']['labels'] === ['field.cattle.io/projectId' => 'p-123']
        && ! isset($request['metadata']['annotations'][NamespaceSecurityReconciler::ANNOTATION]));
});
