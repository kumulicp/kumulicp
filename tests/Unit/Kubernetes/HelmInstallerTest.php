<?php

use App\Integrations\ServerManagers\HelmKubernetes\API\HelmInstaller;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeHelmInstaller(): HelmInstaller
{
    $server = Server::factory()->create([
        'interface' => 'helm_k8s',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
    ]);

    $organization = Organization::factory()->create();

    $org_server = OrgServer::create([
        'organization_id' => $organization->id,
        'server_id' => $server->id,
    ]);

    return new HelmInstaller($organization, $org_server);
}

// A tiny in-memory Kubernetes API: GET returns what was POSTed/PATCHed
// (404 otherwise), POST assigns a uid (the Job's is $job_uid), PATCH merges,
// DELETE removes. $failures maps a kind to an error message to answer its
// POST with a 403 instead. Returns the store (collection path/name => object)
// so tests can assert on the cluster's final state.
function fakeHelmCluster(array $failures = [], string $job_uid = 'job-uid-123'): ArrayObject
{
    $store = new ArrayObject;

    Http::fake(function (Request $request) use ($store, $failures, $job_uid) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $body = json_decode($request->body(), true) ?? [];

        switch ($request->method()) {
            case 'GET':
                return isset($store[$path])
                    ? Http::response($store[$path])
                    : Http::response(['kind' => 'Status', 'message' => 'not found'], 404);

            case 'POST':
                if (isset($failures[$body['kind']])) {
                    return Http::response(['kind' => 'Status', 'message' => $failures[$body['kind']]], 403);
                }

                $body['metadata']['uid'] = $body['kind'] === 'Job' ? $job_uid : 'uid-'.$body['metadata']['name'];
                $store[$path.'/'.$body['metadata']['name']] = $body;

                return Http::response($body, 201);

            case 'PATCH':
                $store[$path] = array_replace_recursive($store[$path], $body);

                return Http::response($store[$path]);

            case 'DELETE':
                unset($store[$path]);

                return Http::response(['kind' => 'Status', 'status' => 'Success']);
        }
    });

    return $store;
}

// The kind of every manifest sent with the given HTTP method, in order.
function sentKinds(string $method): array
{
    return Http::recorded()
        ->filter(fn ($pair) => $pair[0]->method() === $method)
        ->map(fn ($pair) => json_decode($pair[0]->body(), true)['kind'] ?? null)
        ->values()
        ->all();
}

// The resource collection (e.g. "serviceaccounts") of every DELETE, in order.
function deletedCollections(): array
{
    return Http::recorded()
        ->filter(fn ($pair) => $pair[0]->method() === 'DELETE')
        ->map(fn ($pair) => preg_match('#/([a-z]+)/[^/]+$#', parse_url($pair[0]->url(), PHP_URL_PATH), $m) ? $m[1] : null)
        ->values()
        ->all();
}

function storedObject(ArrayObject $store, string $kind): ?array
{
    foreach ($store as $object) {
        if ($object['kind'] === $kind) {
            return $object;
        }
    }

    return null;
}

it('creates the Job and returns success once it is accepted, without waiting for it to finish', function () {
    fakeHelmCluster();

    $installer = makeHelmInstaller();
    $result = $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n");

    expect($result['success'])->toBeTrue();
    expect($result['output'])->toContain('nextcloud-5iy7z');
    expect($result['exit_code'])->toBe(0);

    // Never reads the Job's pods/logs, or deletes anything.
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/pods')
        || str_contains($request->url(), '/log'));
    expect(deletedCollections())->toBe([]);
});

it('attaches an ownerReference to the values ConfigMap once the Job uid is known', function () {
    $store = fakeHelmCluster();

    $installer = makeHelmInstaller();
    $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n");

    $owner = storedObject($store, 'ConfigMap')['metadata']['ownerReferences'][0];

    expect($owner['uid'])->toBe('job-uid-123');
    expect($owner['kind'])->toBe('Job');
});

it('always creates a fresh per-release ServiceAccount and RoleBinding, even for a plain uninstall', function () {
    $store = fakeHelmCluster();

    $installer = makeHelmInstaller();
    $result = $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['uninstall', 'nextcloud-5iy7z', '--ignore-not-found']);

    expect($result['success'])->toBeTrue();

    expect(sentKinds('POST'))->toBe(['ServiceAccount', 'RoleBinding', 'Job']);
    // ServiceAccount + RoleBinding re-applied with owner refs.
    expect(sentKinds('PATCH'))->toBe(['ServiceAccount', 'RoleBinding']);

    expect(storedObject($store, 'ConfigMap'))->toBeNull();
    expect(storedObject($store, 'Secret'))->toBeNull();
});

it('wires credentials into a Secret via envFrom and attaches its ownerReference too', function () {
    $store = fakeHelmCluster();

    $installer = makeHelmInstaller();
    $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n", [
        'HELM_REPO_USERNAME' => 'deploy',
        'HELM_REPO_PASSWORD' => 'secret',
    ]);

    expect(sentKinds('POST'))->toBe(['ServiceAccount', 'RoleBinding', 'ConfigMap', 'Secret', 'Job']);
    // all four supporting objects re-applied with owner refs (Job doesn't own itself).
    expect(sentKinds('PATCH'))->toBe(['ServiceAccount', 'RoleBinding', 'ConfigMap', 'Secret']);

    expect(storedObject($store, 'Secret')['metadata']['ownerReferences'][0]['uid'])->toBe('job-uid-123');
    expect(storedObject($store, 'Job')['spec']['template']['spec']['containers'][0]['envFrom'][0]['secretRef']['name'])
        ->toBe(storedObject($store, 'Secret')['metadata']['name']);
});

it('bails out without creating anything else if the ServiceAccount create fails', function () {
    fakeHelmCluster(['ServiceAccount' => 'namespace not found']);

    $installer = makeHelmInstaller();
    $result = $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n");

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('namespace not found');

    expect(sentKinds('POST'))->toBe(['ServiceAccount']);
});

it('rolls back the ServiceAccount if the RoleBinding create fails', function () {
    fakeHelmCluster(['RoleBinding' => 'forbidden']);

    $installer = makeHelmInstaller();
    $result = $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n");

    expect($result['success'])->toBeFalse();

    // ServiceAccount (succeeded) + RoleBinding (failed) -- never reaches the ConfigMap/Job creates.
    expect(sentKinds('POST'))->toBe(['ServiceAccount', 'RoleBinding']);
    expect(deletedCollections())->toContain('serviceaccounts');
});

it('rolls back the ServiceAccount, RoleBinding, and ConfigMap if the credentials Secret create fails', function () {
    fakeHelmCluster(['Secret' => 'forbidden']);

    $installer = makeHelmInstaller();
    $result = $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n", [
        'HELM_REPO_USERNAME' => 'deploy',
        'HELM_REPO_PASSWORD' => 'secret',
    ]);

    expect($result['success'])->toBeFalse();

    // ServiceAccount + RoleBinding + ConfigMap (succeeded) + Secret (failed) -- never reaches the Job create.
    expect(sentKinds('POST'))->toBe(['ServiceAccount', 'RoleBinding', 'ConfigMap', 'Secret']);
    expect(deletedCollections())->toContain('serviceaccounts', 'rolebindings', 'configmaps');
});

it('rolls back the ServiceAccount, RoleBinding, ConfigMap, and Secret if the Job create itself fails', function () {
    $store = fakeHelmCluster(['Job' => 'forbidden']);

    $installer = makeHelmInstaller();
    $result = $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n", [
        'HELM_REPO_USERNAME' => 'deploy',
        'HELM_REPO_PASSWORD' => 'secret',
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe('forbidden');

    expect(deletedCollections())->toContain('serviceaccounts', 'rolebindings', 'configmaps', 'secrets');
    expect($store->count())->toBe(0);
});

it('attaches an ownerReference to the ServiceAccount and RoleBinding once the Job uid is known', function () {
    $store = fakeHelmCluster();

    $installer = makeHelmInstaller();
    $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install']);

    expect(storedObject($store, 'ServiceAccount')['metadata']['ownerReferences'][0]['uid'])->toBe('job-uid-123');
    expect(storedObject($store, 'RoleBinding')['metadata']['ownerReferences'][0]['uid'])->toBe('job-uid-123');
});
