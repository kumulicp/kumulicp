<?php

use App\Integrations\ServerManagers\HelmKubernetes\Support\KubernetesApiClient;
use App\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makePreflightApiClient(): KubernetesApiClient
{
    return new KubernetesApiClient(Server::factory()->create([
        'interface' => 'helm_k8s',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
    ]));
}

function warningHeader(string $text): string
{
    return '299 - "'.str_replace('"', '\\"', $text).'"';
}

it('merge-patches an object, with a dry-run flag when asked', function () {
    Http::fake(['*' => Http::response(['metadata' => ['name' => 'demo']])]);

    $result = makePreflightApiClient()->patch('v1', 'Namespace', 'demo', ['metadata' => ['labels' => ['a' => 'b']]], dry_run: true);

    expect($result['success'])->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->url() === 'https://cluster.example.com:6443/api/v1/namespaces/demo?dryRun=All'
        && $request->hasHeader('Content-Type', 'application/merge-patch+json')
        && json_decode($request->body(), true) === ['metadata' => ['labels' => ['a' => 'b']]]);
});

it('does not add the dry-run flag by default', function () {
    Http::fake(['*' => Http::response([])]);

    makePreflightApiClient()->patch('v1', 'Namespace', 'demo', ['metadata' => ['labels' => ['a' => 'b']]]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://cluster.example.com:6443/api/v1/namespaces/demo');
});

it('returns the API server\'s Warning headers, unescaped, in order', function () {
    Http::fake(['*' => Http::response([], 200, ['Warning' => [
        warningHeader('existing pods in namespace "demo" violate the new PodSecurity enforce level "baseline:latest"'),
        warningHeader('web-1: hostPath volumes (volume "h")'),
    ]])]);

    $result = makePreflightApiClient()->patch('v1', 'Namespace', 'demo', [], dry_run: true);

    expect($result['warnings'])->toBe([
        'existing pods in namespace "demo" violate the new PodSecurity enforce level "baseline:latest"',
        'web-1: hostPath volumes (volume "h")',
    ]);
});

it('reads several warnings from one comma-joined header', function () {
    Http::fake(['*' => Http::response([], 200, ['Warning' => '299 - "first", 299 - "second"'])]);

    expect(makePreflightApiClient()->get('v1', 'Namespace', 'demo')['warnings'])->toBe(['first', 'second']);
});

it('returns no warnings when there are none, and for failures', function () {
    Http::fake(['*' => Http::response(['message' => 'not found'], 404)]);

    $result = makePreflightApiClient()->get('v1', 'Namespace', 'demo');

    expect($result['success'])->toBeFalse()
        ->and($result['warnings'])->toBe([]);
});

it('reports an unknown kind as a failure with no warnings', function () {
    Http::fake(['*' => Http::response([], 404)]);

    $result = makePreflightApiClient()->patch('example.io/v1', 'Nothing', 'x', []);

    expect($result)->toMatchArray(['success' => false, 'warnings' => []]);
});
