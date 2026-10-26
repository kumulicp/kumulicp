<?php

use App\Integrations\ServerManagers\HelmKubernetes\Support\HelmReleases;
use App\Integrations\ServerManagers\HelmKubernetes\Support\KubernetesApiClient;
use App\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeHelmReleases(): HelmReleases
{
    return new HelmReleases(new KubernetesApiClient(Server::factory()->create([
        'interface' => 'helm_k8s',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
    ])));
}

// How Helm 3 stores a revision: the release JSON, gzipped, base64-encoded by
// Helm, then base64-encoded again by the Kubernetes API as Secret data.
function helmReleaseSecret(string $release, int $version, string $status, array $config = []): array
{
    $json = json_encode([
        'name' => $release,
        'version' => $version,
        'info' => ['status' => $status],
        'config' => $config,
    ]);

    return [
        'metadata' => [
            'name' => "sh.helm.release.v1.{$release}.v{$version}",
            'labels' => ['owner' => 'helm', 'name' => $release, 'version' => (string) $version, 'status' => $status],
        ],
        'type' => 'helm.sh/release.v1',
        'data' => ['release' => base64_encode(base64_encode(gzencode($json)))],
    ];
}

it('reads the status of the latest revision, not the highest-sorting secret name', function () {
    Http::fake(['*' => Http::response(['items' => [
        helmReleaseSecret('nextcloud', 2, 'superseded'),
        helmReleaseSecret('nextcloud', 10, 'deployed'),
        helmReleaseSecret('nextcloud', 9, 'superseded'),
    ]])]);

    expect(makeHelmReleases()->status('nextcloud', 'demo'))->toBe('deployed');

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return parse_url($request->url(), PHP_URL_PATH) === '/api/v1/namespaces/demo/secrets'
            && $query['labelSelector'] === 'owner=helm,name=nextcloud';
    });
});

it('reports a pending status for a release stuck mid-upgrade', function () {
    Http::fake(['*' => Http::response(['items' => [
        helmReleaseSecret('nextcloud', 1, 'superseded'),
        helmReleaseSecret('nextcloud', 2, 'pending-upgrade'),
    ]])]);

    expect(makeHelmReleases()->status('nextcloud', 'demo'))->toBe('pending-upgrade');
});

it('returns a null status when the release does not exist', function () {
    Http::fake(['*' => Http::response(['items' => []])]);

    expect(makeHelmReleases()->status('nextcloud', 'demo'))->toBeNull();
});

it('returns a null status when the cluster call fails', function () {
    Http::fake(['*' => Http::response(['message' => 'forbidden'], 403)]);

    expect(makeHelmReleases()->status('nextcloud', 'demo'))->toBeNull();
});

it('decodes the latest revision\'s user-supplied values like `helm get values`', function () {
    Http::fake(['*' => Http::response(['items' => [
        helmReleaseSecret('nextcloud', 1, 'superseded', ['replicaCount' => 1]),
        helmReleaseSecret('nextcloud', 2, 'deployed', ['replicaCount' => 3, 'ingress' => ['enabled' => true]]),
    ]])]);

    $result = makeHelmReleases()->values('nextcloud', 'demo');

    expect($result['success'])->toBeTrue();
    expect($result['values'])->toBe(['replicaCount' => 3, 'ingress' => ['enabled' => true]]);
});

it('fails to read values for a release that is not found', function () {
    Http::fake(['*' => Http::response(['items' => []])]);

    $result = makeHelmReleases()->values('nextcloud', 'demo');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe('release: not found');
});

it('fails to read values when the release data cannot be decoded', function () {
    $secret = helmReleaseSecret('nextcloud', 1, 'deployed');
    $secret['data']['release'] = base64_encode('not a helm release');

    Http::fake(['*' => Http::response(['items' => [$secret]])]);

    $result = makeHelmReleases()->values('nextcloud', 'demo');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('Unable to decode');
});

it('lists only the pending-* revision secrets, asking the API for metadata only', function () {
    Http::fake(['*' => Http::response(['items' => [
        helmReleaseSecret('nextcloud', 1, 'superseded'),
        helmReleaseSecret('nextcloud', 2, 'deployed'),
        helmReleaseSecret('nextcloud', 3, 'pending-upgrade'),
        helmReleaseSecret('nextcloud', 4, 'pending-rollback'),
    ]])]);

    expect(makeHelmReleases()->pendingSecretNames('nextcloud', 'demo'))->toBe([
        'sh.helm.release.v1.nextcloud.v3',
        'sh.helm.release.v1.nextcloud.v4',
    ]);

    Http::assertSent(fn (Request $request) => str_contains($request->header('Accept')[0], 'PartialObjectMetadataList'));
});
