<?php

use App\Integrations\ServerManagers\HelmKubernetes\API\KubernetesNamespace;
use App\Integrations\ServerManagers\HelmKubernetes\API\Pod;
use App\Organization;
use App\OrgServer;
use App\Plan;
use App\Server;
use App\Support\Security\NamespaceSecurityReconciler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeSecuredOrgServer(?string $mode, ?string $tier): OrgServer
{
    $server = Server::factory()->create([
        'interface' => 'helm_k8s',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => array_filter(['k8s_auth_type' => 'bearer_token', 'security_mode' => $mode]),
    ]);

    $plan = Plan::factory()->create(['settings' => $tier ? ['security' => ['tier' => $tier]] : []]);
    $organization = Organization::factory()->create(['plan_id' => $plan->id]);

    return OrgServer::create(['organization_id' => $organization->id, 'server_id' => $server->id]);
}

function namespaceObject(OrgServer $org_server, array $labels = [], array $annotations = []): array
{
    return [
        'metadata' => ['name' => $org_server->organization->slug, 'labels' => $labels, 'annotations' => $annotations],
        'status' => ['phase' => 'Active'],
    ];
}

// Every GET (the reconciler's read, and apply()'s own existence check) returns
// the namespace; a create (POST) succeeds; a merge patch returns $patch_response
function fakeNamespaceApi(array $namespace, mixed $patch_response = null): void
{
    Http::fake(fn (Request $request) => match ($request->method()) {
        'GET' => Http::response($namespace),
        'PATCH' => $patch_response ?? Http::response($namespace),
        default => Http::response($namespace, 201),
    });
}

function isPatch(): Closure
{
    return fn (Request $request) => $request->method() === 'PATCH';
}

it('makes no cluster calls at all by default', function () {
    $org_server = makeSecuredOrgServer(null, 'restricted');
    Http::fake();

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->update();

    expect($result['action'])->toBe('unmanaged');
    Http::assertNothingSent();
});

it('does not touch namespaces when the server only observes', function () {
    $org_server = makeSecuredOrgServer('observe', 'restricted');
    Http::fake();

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->update();

    expect($result['action'])->toBe('unmanaged');
    Http::assertNothingSent();
});

it('creates a namespace with exactly the same manifest as before when unmanaged', function () {
    $org_server = makeSecuredOrgServer(null, 'restricted');
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? Http::response(['message' => 'not found'], 404) : Http::response([], 201));

    (new KubernetesNamespace($org_server->organization, $org_server))->create();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->data() === [
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => ['name' => $org_server->organization->slug],
        ]);
});

it('creates a namespace with the plan\'s pod security labels when managed', function () {
    $org_server = makeSecuredOrgServer('managed', 'baseline');
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? Http::response(['message' => 'not found'], 404) : Http::response([], 201));

    (new KubernetesNamespace($org_server->organization, $org_server))->create();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['metadata']['labels']['pod-security.kubernetes.io/enforce'] === 'baseline'
        && $request['metadata']['labels']['pod-security.kubernetes.io/warn'] === 'restricted'
        && $request['metadata']['annotations'][NamespaceSecurityReconciler::ANNOTATION] === 'baseline');
});

it('creates a namespace with no extra metadata for the none tier even when managed', function () {
    $org_server = makeSecuredOrgServer('managed', null);
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? Http::response(['message' => 'not found'], 404) : Http::response([], 201));

    (new KubernetesNamespace($org_server->organization, $org_server))->create();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && array_keys($request['metadata']) === ['name']);
});

it('merge-patches an existing namespace to add the plan\'s labels', function () {
    $org_server = makeSecuredOrgServer('managed', 'observe');
    fakeNamespaceApi(namespaceObject($org_server));

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->update();

    expect($result)->toMatchArray(['status' => 'success', 'action' => 'updated']);
    Http::assertSent(function (Request $request) use ($org_server) {
        $body = json_decode($request->body(), true);

        return $request->method() === 'PATCH'
            && $request->url() === 'https://cluster.example.com:6443/api/v1/namespaces/'.$org_server->organization->slug
            && $request->hasHeader('Content-Type', 'application/merge-patch+json')
            && $body['metadata']['labels']['pod-security.kubernetes.io/warn'] === 'restricted'
            && $body['metadata']['annotations'][NamespaceSecurityReconciler::ANNOTATION] === 'observe';
    });
});

it('does not patch a namespace that already matches', function () {
    $org_server = makeSecuredOrgServer('managed', 'observe');
    $labels = ['pod-security.kubernetes.io/warn' => 'restricted', 'pod-security.kubernetes.io/audit' => 'restricted'];
    fakeNamespaceApi(namespaceObject($org_server, $labels, [NamespaceSecurityReconciler::ANNOTATION => 'observe']));

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->update();

    expect($result['action'])->toBe('unchanged');
    Http::assertNotSent(isPatch());
});

it('removes the labels it owns when the plan goes back to no tier', function () {
    $org_server = makeSecuredOrgServer('managed', null);
    $labels = ['pod-security.kubernetes.io/warn' => 'restricted', 'unrelated' => 'keep'];
    fakeNamespaceApi(namespaceObject($org_server, $labels, [NamespaceSecurityReconciler::ANNOTATION => 'observe']));

    (new KubernetesNamespace($org_server->organization, $org_server))->update();

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $request->method() === 'PATCH'
            && $body['metadata']['labels'] === ['pod-security.kubernetes.io/warn' => null]
            && $body['metadata']['annotations'] === [NamespaceSecurityReconciler::ANNOTATION => null];
    });
});

it('leaves labels it does not own alone when the plan has no tier', function () {
    $org_server = makeSecuredOrgServer('managed', null);
    fakeNamespaceApi(namespaceObject($org_server, ['pod-security.kubernetes.io/enforce' => 'baseline']));

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->update();

    expect($result['action'])->toBe('unchanged');
    Http::assertNotSent(isPatch());
});

it('reports what it would change without changing it on a dry run', function () {
    $org_server = makeSecuredOrgServer('managed', 'observe');
    fakeNamespaceApi(namespaceObject($org_server));

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->reconcileSecurity(apply: false);

    expect($result['action'])->toBe('would_update')
        ->and($result['changes']['labels'])->toHaveKey('pod-security.kubernetes.io/warn');
    Http::assertNotSent(isPatch());
});

it('reports a failed patch (e.g. forbidden) without throwing', function () {
    $org_server = makeSecuredOrgServer('managed', 'observe');
    fakeNamespaceApi(namespaceObject($org_server), Http::response(['kind' => 'Status', 'message' => 'namespaces "x" is forbidden'], 403));

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->update();

    expect($result)->toMatchArray(['status' => 'failed', 'action' => 'error'])
        ->and($result['response'])->toContain('forbidden');
});

it('reads the logs of the most recent install job for a release', function () {
    $org_server = makeSecuredOrgServer(null, null);

    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/jobs') => Http::response(['items' => [
                ['metadata' => ['name' => 'helm-install-nc-new', 'creationTimestamp' => '2026-10-03T10:00:00Z']],
                ['metadata' => ['name' => 'helm-install-nc-old', 'creationTimestamp' => '2026-10-03T09:00:00Z']],
            ]]),
            str_ends_with($path, '/pods') => Http::response(['items' => [
                ['metadata' => ['name' => 'helm-pod'], 'spec' => ['containers' => [['name' => 'helm']]]],
            ]]),
            str_ends_with($path, '/log') => Http::response('would violate PodSecurity', 200, ['Content-Type' => 'text/plain']),
            default => Http::response([], 404),
        };
    });

    $logs = (new Pod($org_server->organization, $org_server))->latestInstallLogs('nc');

    expect($logs)->toBe('would violate PodSecurity');
    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'labelSelector=job-name=helm-install-nc-new'));
});

it('returns no logs when the release has no install job', function () {
    $org_server = makeSecuredOrgServer(null, null);
    Http::fake(['*' => Http::response(['items' => []])]);

    expect((new Pod($org_server->organization, $org_server))->latestInstallLogs('nc'))->toBeNull();
});
