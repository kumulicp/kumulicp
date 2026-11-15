<?php

use App\AppInstance;
use App\Integrations\ServerManagers\Kubernetes\Charts\HelmChart;
use App\Integrations\ServerManagers\Rancher\API\Application;
use App\Integrations\ServerManagers\Rancher\API\KubernetesNamespace;
use App\Integrations\ServerManagers\Rancher\RancherConfigTest;
use App\Integrations\ServerManagers\Rancher\RancherProfile;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

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

function rancherNamespaceApi(): KubernetesNamespace
{
    $server = rancherConfigTestServer();
    $org_server = OrgServer::create([
        'organization_id' => Organization::factory()->create()->id,
        'server_id' => $server->id,
    ]);

    return new KubernetesNamespace($org_server->organization, $org_server);
}

it('creates the organization namespace when Rancher answers 403 for a namespace that does not exist yet', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response(['type' => 'error', 'code' => 'Forbidden'], 403)
        : Http::response(['id' => 'x'], 201));

    $result = rancherNamespaceApi()->create();

    expect($result['status'])->toBe('success');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/namespaces'));
});

it('reports a real permission error when the namespace cannot be created either', function () {
    Http::fake(fn () => Http::response(['type' => 'error', 'message' => 'forbidden'], 403));

    $result = rancherNamespaceApi()->create();

    expect($result['status'])->toBe('failed')
        ->and($result['response'])->toContain('forbidden');
});

it('does not treat a 403 on anything other than a namespace as missing', function () {
    Http::fake(fn () => Http::response(['message' => 'forbidden'], 403));

    $api = rancherNamespaceApi()->api();
    $result = $api->apply(['apiVersion' => 'v1', 'kind' => 'ConfigMap', 'metadata' => ['name' => 'x', 'namespace' => 'demo']]);

    expect($result['success'])->toBeFalse();
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

function rancherApplicationApi(): Application
{
    $server = rancherConfigTestServer();
    $org_server = OrgServer::create([
        'organization_id' => Organization::factory()->create()->id,
        'server_id' => $server->id,
    ]);

    return new Application($org_server->organization, $org_server);
}

it('reads a deployed catalog app as active and logs nothing', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['metadata' => ['state' => ['name' => 'deployed', 'transitioning' => false]]])]);

    $app = rancherApplicationApi();
    $chart = Mockery::mock(HelmChart::class);
    $chart->shouldReceive('chartName')->andReturn('wordpress-abc');
    $chart->shouldReceive('namespace')->andReturn('demo');

    expect($app->isActive(new AppInstance, $chart))->toBe(1);
    Log::shouldNotHaveReceived('warning');
});

it('logs what Rancher reported when a catalog app is in a state it does not recognise', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['metadata' => ['state' => ['name' => 'superseded', 'transitioning' => false]]])]);

    $app = rancherApplicationApi();
    $chart = Mockery::mock(HelmChart::class);
    $chart->shouldReceive('chartName')->andReturn('wordpress-abc');
    $chart->shouldReceive('namespace')->andReturn('demo');

    expect($app->isActive(new AppInstance, $chart))->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'does not recognise') && $context['state'] === 'superseded')->once();
});

function rancherChartStub(): MockInterface
{
    $chart = Mockery::mock(HelmChart::class);
    $chart->shouldReceive('chartName')->andReturn('wordpress-abc');
    $chart->shouldReceive('namespace')->andReturn('demo');

    return $chart;
}

it('logs a catalog app lookup that fails for a reason other than not existing', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['message' => 'forbidden'], 403)]);

    expect(rancherApplicationApi()->isActive(new AppInstance, rancherChartStub()))->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $context['status'] === 403)->once();
});

it('does not log a catalog app that simply does not exist yet', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['message' => 'not found'], 404)]);

    expect(rancherApplicationApi()->isActive(new AppInstance, rancherChartStub()))->toBe(0);
    Log::shouldNotHaveReceived('warning');
});
