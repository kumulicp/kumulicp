<?php

use App\Integrations\ServerManagers\Rancher\RancherConfigTest;
use App\Integrations\ServerManagers\Rancher\RancherProfile;
use App\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function rancherConfigTestServer(array $overrides = []): Server
{
    return Server::factory()->create(array_merge([
        'interface' => 'rancher',
        'address' => 'https://rancher.example.com',
        'api_key' => 'token-abc',
        'api_secret' => 'shhh',
        'settings' => ['project_id' => 'p-abc12'],
    ], $overrides));
}

it('is registered as the rancher profile config test', function () {
    expect((new RancherProfile)->configTest())->toBe(RancherConfigTest::class);
});

it('creates a namespace and secret, then deletes the namespace', function () {
    Http::fake([
        'https://rancher.example.com/v1/namespaces' => Http::response(['id' => 'x'], 201),
        'https://rancher.example.com/k8s/clusters/local/*' => Http::response(['kind' => 'Secret'], 201),
        'https://rancher.example.com/v1/namespaces/*' => Http::response([], 200),
    ]);

    $result = (new RancherConfigTest(rancherConfigTestServer()))->run();

    expect($result['success'])->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://rancher.example.com/v1/namespaces'
        && $request['metadata']['labels']['field.cattle.io/projectId'] === 'p-abc12');
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_starts_with($request->url(), 'https://rancher.example.com/v1/namespaces/kumuli-config-test-'));
});

it('fails without creating anything when credentials are missing', function () {
    Http::fake();

    $result = (new RancherConfigTest(rancherConfigTestServer(['api_secret' => null])))->run();

    expect($result['success'])->toBeFalse();
    Http::assertNothingSent();
});

it('fails without creating anything when project_id is not set', function () {
    Http::fake();

    $result = (new RancherConfigTest(rancherConfigTestServer(['settings' => []])))->run();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('project_id');
    Http::assertNothingSent();
});

it('reports the failing step and still cleans up the namespace', function () {
    Http::fake([
        'https://rancher.example.com/v1/namespaces' => Http::response(['id' => 'x'], 201),
        'https://rancher.example.com/k8s/clusters/local/*' => Http::response(['message' => 'forbidden'], 403),
        'https://rancher.example.com/v1/namespaces/*' => Http::response([], 200),
    ]);

    $result = (new RancherConfigTest(rancherConfigTestServer()))->run();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Secret')->toContain('forbidden');
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
});

it('does not try to delete a namespace that was never created', function () {
    Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

    $result = (new RancherConfigTest(rancherConfigTestServer()))->run();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Namespace');
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
});
