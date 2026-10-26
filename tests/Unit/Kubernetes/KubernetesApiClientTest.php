<?php

use App\Integrations\ServerManagers\HelmKubernetes\Support\KubernetesApiClient;
use App\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeK8sApiClient(): KubernetesApiClient
{
    return new KubernetesApiClient(Server::factory()->create([
        'interface' => 'helm_k8s',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
    ]));
}

function k8sNotFound()
{
    return Http::response(['kind' => 'Status', 'message' => 'not found'], 404);
}

function configMapManifest(array $data = ['key' => 'value']): array
{
    return [
        'apiVersion' => 'v1',
        'kind' => 'ConfigMap',
        'metadata' => ['name' => 'settings', 'namespace' => 'demo'],
        'data' => $data,
    ];
}

it('creates an object that does not exist yet', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? k8sNotFound() : Http::response(['created' => true], 201));

    $result = makeK8sApiClient()->apply(configMapManifest());

    expect($result['success'])->toBeTrue();
    expect($result['data'])->toBe(['created' => true]);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://cluster.example.com:6443/api/v1/namespaces/demo/configmaps'
        && $request['data'] === ['key' => 'value']);
});

it('merge-patches an existing object that differs from the manifest', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response(configMapManifest(['key' => 'old']))
        : Http::response(['patched' => true]));

    $result = makeK8sApiClient()->apply(configMapManifest(['key' => 'new']));

    expect($result['success'])->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->url() === 'https://cluster.example.com:6443/api/v1/namespaces/demo/configmaps/settings'
        && $request->hasHeader('Content-Type', 'application/merge-patch+json')
        && json_decode($request->body(), true)['data'] === ['key' => 'new']);
});

it('issues no write when the existing object already matches, ignoring server-set fields', function () {
    $existing = configMapManifest();
    $existing['metadata']['uid'] = 'abc';
    $existing['metadata']['resourceVersion'] = '42';

    Http::fake(['*' => Http::response($existing)]);

    $result = makeK8sApiClient()->apply(configMapManifest());

    expect($result['success'])->toBeTrue();
    Http::assertSentCount(1);
});

it('applies cluster-scoped kinds without a namespace', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? k8sNotFound() : Http::response([], 201));

    makeK8sApiClient()->apply(['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => 'demo']]);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://cluster.example.com:6443/api/v1/namespaces');
});

it('uses the namespace argument when the manifest has none', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? k8sNotFound() : Http::response([], 201));

    $manifest = configMapManifest();
    unset($manifest['metadata']['namespace']);

    makeK8sApiClient()->apply($manifest, 'other');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://cluster.example.com:6443/api/v1/namespaces/other/configmaps');
});

it('discovers the plural resource name for kinds it does not know, such as CRDs', function () {
    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/apis/example.com/v1' => Http::response(['resources' => [
                ['name' => 'widgets/status', 'kind' => 'Widget', 'namespaced' => true],
                ['name' => 'widgets', 'kind' => 'Widget', 'namespaced' => true],
            ]]),
            $request->method() === 'GET' => k8sNotFound(),
            default => Http::response([], 201),
        };
    });

    $result = makeK8sApiClient()->apply([
        'apiVersion' => 'example.com/v1',
        'kind' => 'Widget',
        'metadata' => ['name' => 'w1', 'namespace' => 'demo'],
    ]);

    expect($result['success'])->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://cluster.example.com:6443/apis/example.com/v1/namespaces/demo/widgets');
});

it('fails cleanly for a kind the cluster does not serve', function () {
    Http::fake(['*' => Http::response(['resources' => []])]);

    $result = makeK8sApiClient()->get('example.com/v1', 'Gadget', 'g1', 'demo');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('Unknown resource kind Gadget');
});

it('rejects a manifest without a name', function () {
    Http::fake();

    $result = makeK8sApiClient()->apply(['apiVersion' => 'v1', 'kind' => 'ConfigMap', 'metadata' => []], 'demo');

    expect($result['success'])->toBeFalse();
    Http::assertNothingSent();
});

it('surfaces the API error message from a Status response', function () {
    Http::fake(['*' => Http::response(['kind' => 'Status', 'message' => 'secrets "x" is forbidden'], 403)]);

    $result = makeK8sApiClient()->get('v1', 'Secret', 'x', 'demo');

    expect($result)->toMatchArray(['success' => false, 'status' => 403, 'data' => ['kind' => 'Status', 'message' => 'secrets "x" is forbidden'], 'error' => 'secrets "x" is forbidden']);
});

it('treats deleting an already-gone object as success and uses background propagation', function () {
    Http::fake(['*' => k8sNotFound()]);

    $result = makeK8sApiClient()->delete('batch/v1', 'Job', 'scan', 'demo');

    expect($result['success'])->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://cluster.example.com:6443/apis/batch/v1/namespaces/demo/jobs/scan'
        && $request['propagationPolicy'] === 'Background');
});

it('reports a delete the API refuses', function () {
    Http::fake(['*' => Http::response(['message' => 'forbidden'], 403)]);

    $result = makeK8sApiClient()->delete('v1', 'Secret', 'x', 'demo');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe('forbidden');
});

it('lists by label selector and can ask for metadata only', function () {
    Http::fake(['*' => Http::response(['items' => [['metadata' => ['name' => 'a']]]])]);

    $result = makeK8sApiClient()->list('v1', 'Secret', 'demo', 'owner=helm,name=rel', metadata_only: true);

    expect($result['data']['items'])->toHaveCount(1);

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return parse_url($request->url(), PHP_URL_PATH) === '/api/v1/namespaces/demo/secrets'
            && $query['labelSelector'] === 'owner=helm,name=rel'
            && str_contains($request->header('Accept')[0], 'PartialObjectMetadataList');
    });
});

it('returns the concatenated logs of every matching pod, reading each pod\'s default container', function () {
    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match ($path) {
            '/api/v1/namespaces/demo/pods' => Http::response(['items' => [
                ['metadata' => ['name' => 'scan-a'], 'spec' => ['containers' => [['name' => 'trivy'], ['name' => 'sidecar']]]],
                ['metadata' => ['name' => 'scan-b', 'annotations' => ['kubectl.kubernetes.io/default-container' => 'main']], 'spec' => ['containers' => [['name' => 'sidecar'], ['name' => 'main']]]],
            ]]),
            '/api/v1/namespaces/demo/pods/scan-a/log' => Http::response("first\n"),
            '/api/v1/namespaces/demo/pods/scan-b/log' => Http::response("second\n"),
        };
    });

    $result = makeK8sApiClient()->podLogs('job-name=scan', 'demo');

    expect($result['success'])->toBeTrue();
    expect($result['data'])->toBe("first\nsecond");

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/v1/namespaces/demo/pods' => $query['labelSelector'] === 'job-name=scan',
            '/api/v1/namespaces/demo/pods/scan-a/log' => $query['container'] === 'trivy' && $request->hasHeader('Accept', 'text/plain'),
            '/api/v1/namespaces/demo/pods/scan-b/log' => $query['container'] === 'main',
        };
    });
});

it('returns empty logs when no pod matches', function () {
    Http::fake(['*' => Http::response(['items' => []])]);

    $result = makeK8sApiClient()->podLogs('job-name=scan', 'demo');

    expect($result['success'])->toBeTrue();
    expect($result['data'])->toBe('');
});

it('turns a connection failure into a failed result instead of throwing', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: connection refused'));

    $result = makeK8sApiClient()->get('v1', 'Namespace', 'demo');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('connection refused');
});
