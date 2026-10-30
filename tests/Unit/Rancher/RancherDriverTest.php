<?php

// The Rancher driver is the Kubernetes driver pointed at Rancher: these pin
// down what it sends to Rancher (Steve paths, basic auth, whole-object PUTs)
// and how it reads Rancher's `metadata.state`, i.e. the parts it overrides.

use App\AppInstance;
use App\Application as AppModel;
use App\Exceptions\ConnectionFailedException;
use App\Integrations\ServerManagers\Kubernetes\Interfaces\KubernetesWebInterface;
use App\Integrations\ServerManagers\Rancher\API\Application;
use App\Integrations\ServerManagers\Rancher\API\Ingress;
use App\Integrations\ServerManagers\Rancher\API\Job;
use App\Integrations\ServerManagers\Rancher\API\KubernetesNamespace;
use App\Integrations\ServerManagers\Rancher\API\Middleware;
use App\Integrations\ServerManagers\Rancher\API\PersistentVolumeClaim;
use App\Integrations\ServerManagers\Rancher\API\Pod;
use App\Integrations\ServerManagers\Rancher\Charts\HelmChart;
use App\Integrations\ServerManagers\Rancher\Charts\Ingress\IngressChart;
use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;
use App\Integrations\ServerManagers\Rancher\Charts\Middleware\MiddlewareChart;
use App\Integrations\ServerManagers\Rancher\Interfaces\RancherWebInterface;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const RANCHER_API = 'https://rancher.example.com';

function steveOrgServer(): OrgServer
{
    $server = Server::factory()->create([
        'interface' => 'rancher',
        'address' => RANCHER_API,
        'api_key' => 'token-abc',
        'api_secret' => 'secret-xyz',
        'settings' => ['project_id' => 'p-1'],
    ]);

    return OrgServer::create([
        'organization_id' => Organization::factory()->create()->id,
        'server_id' => $server->id,
    ]);
}

function rancherNotFound()
{
    return Http::response(['type' => 'error', 'status' => 404, 'message' => 'not found'], 404);
}

function rancherState(string $name, bool $transitioning = false): array
{
    return ['metadata' => ['state' => ['name' => $name, 'transitioning' => $transitioning]]];
}

function demoInstance(string $name = 'demo-app'): AppInstance
{
    $instance = (new AppInstance)->forceFill(['name' => $name]);
    $instance->setRelation('application', (new AppModel)->forceFill(['slug' => 'demo', 'name' => 'Demo']));

    return $instance;
}

function ingressChart(string $name, array $rules): IngressChart
{
    $chart = new class((new Organization)->forceFill(['slug' => 'demo']), demoInstance($name)) extends IngressChart
    {
        public array $rules = [];

        public function values(): array
        {
            return [
                'apiVersion' => 'networking.k8s.io/v1',
                'kind' => 'Ingress',
                'metadata' => ['name' => $this->name, 'namespace' => 'demo'],
                'spec' => ['rules' => $this->rules],
            ];
        }
    };
    $chart->rules = $rules;

    return $chart;
}

function middlewareChart(string $name): MiddlewareChart
{
    return new class((new Organization)->forceFill(['slug' => 'demo']), demoInstance($name)) extends MiddlewareChart
    {
        public function values(): array
        {
            return [
                'apiVersion' => 'traefik.io/v1alpha1',
                'kind' => 'Middleware',
                'metadata' => ['name' => $this->name, 'namespace' => 'demo'],
                'spec' => ['redirectRegex' => ['regex' => '^a', 'replacement' => 'b']],
            ];
        }
    };
}

function helmChart(): HelmChart
{
    return new class((new Organization)->forceFill(['slug' => 'demo', 'name' => 'Demo']), demoInstance()) extends HelmChart
    {
        public $repo_name = 'kumulicp-repo';

        public function values(): array
        {
            return [];
        }

        public function chartName()
        {
            return 'demo-app-demo';
        }

        public function buildChart()
        {
            return ['charts' => [['releaseName' => 'demo-app-demo']]];
        }
    };
}

function jobChart(): JobChart
{
    $chart = new JobChart((new Organization)->forceFill(['slug' => 'demo']), demoInstance());
    $chart->chart = [
        'apiVersion' => 'batch/v1',
        'kind' => 'Job',
        'metadata' => ['name' => 'demo-job-abc', 'namespace' => 'demo'],
    ];

    return $chart;
}

it('authenticates to Rancher with the API key and secret as basic auth', function () {
    $org_server = steveOrgServer();
    Http::fake(['*' => Http::response(rancherState('active'))]);

    (new KubernetesNamespace($org_server->organization, $org_server))->isActive();

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('token-abc:secret-xyz')));
});

it('creates a namespace in the server\'s Rancher project through Rancher\'s own API', function () {
    $org_server = steveOrgServer();
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? rancherNotFound() : Http::response(['created' => true], 201));

    $result = (new KubernetesNamespace($org_server->organization, $org_server))->create();

    expect($result['status'])->toBe('success');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === RANCHER_API.'/v1/namespaces/'.$org_server->organization->slug);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === RANCHER_API.'/v1/namespaces'
        && $request['metadata']['name'] === $org_server->organization->slug
        && $request['metadata']['annotations']['field.cattle.io/projectId'] === 'local:p-1'
        && $request['metadata']['labels']['field.cattle.io/projectId'] === 'p-1');
});

it('deletes a namespace without delete options', function () {
    $org_server = steveOrgServer();
    Http::fake(['*' => Http::response(['deleted' => true])]);

    (new KubernetesNamespace($org_server->organization, $org_server))->remove();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === RANCHER_API.'/v1/namespaces/'.$org_server->organization->slug
        && ! isset($request['propagationPolicy']));
});

it('reads a namespace\'s state from Rancher\'s metadata.state', function (array $body, int $status, int $expected) {
    $org_server = steveOrgServer();
    Http::fake(['*' => Http::response($body, $status)]);

    expect((new KubernetesNamespace($org_server->organization, $org_server))->isActive())->toBe($expected);
})->with([
    'active' => [rancherState('active'), 200, 1],
    'transitioning' => [rancherState('removing', true), 200, 2],
    'some other state' => [rancherState('error'), 200, 0],
    'not found' => [['type' => 'error'], 404, 0],
    'forbidden' => [['type' => 'error'], 403, 0],
]);

it('creates an ingress by POSTing to the namespace-less collection, and skips one with no rules', function () {
    $org_server = steveOrgServer();
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? rancherNotFound() : Http::response(['created' => true], 201));

    $ingress = new Ingress($org_server->organization, $org_server);

    $ingress->create(ingressChart('demo-redirect', []));
    Http::assertNothingSent();

    $ingress->create(ingressChart('demo-redirect', [['host' => 'a.example.com']]));

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === RANCHER_API.'/v1/networking.k8s.io.ingresses'
        && $request['spec']['rules'] === [['host' => 'a.example.com']]);
});

it('updates an ingress with a PUT of the whole object, replacing its rules and keeping Rancher\'s metadata', function () {
    $org_server = steveOrgServer();

    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response([
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => ['name' => 'demo-redirect', 'namespace' => 'demo', 'resourceVersion' => '42', 'state' => ['name' => 'active']],
            'spec' => ['rules' => [['host' => 'old-1.example.com'], ['host' => 'old-2.example.com']]],
        ])
        : Http::response(['replaced' => true]));

    (new Ingress($org_server->organization, $org_server))->update(ingressChart('demo-redirect', [['host' => 'new.example.com']]));

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === RANCHER_API.'/v1/networking.k8s.io.ingresses/demo/demo-redirect'
        && $request['metadata']['resourceVersion'] === '42'
        && $request['spec']['rules'] === [['host' => 'new.example.com']]);
});

it('reads an ingress\'s state from Rancher\'s metadata.state', function (array $body, int $status, int $expected) {
    $org_server = steveOrgServer();
    Http::fake(['*' => Http::response($body, $status)]);

    expect((new Ingress($org_server->organization, $org_server))->isActive(ingressChart('demo-redirect', [])))->toBe($expected);
})->with([
    'active' => [rancherState('active'), 200, 1],
    'transitioning' => [rancherState('updating', true), 200, 2],
    'not found' => [['type' => 'error'], 404, 0],
]);

it('manages traefik middlewares without needing a server setting to opt in', function () {
    $org_server = steveOrgServer();
    Http::fake(['*' => Http::response(rancherState('active'))]);

    expect((new Middleware($org_server->organization, $org_server))->isActive(middlewareChart('demo-redirect')))->toBe(1);

    Http::assertSent(fn (Request $request) => $request->url() === RANCHER_API.'/v1/traefik.io.middlewares/'.$org_server->organization->slug.'/demo-redirect');
});

it('defaults a claim to the Longhorn storage class', function () {
    $org_server = steveOrgServer();
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? rancherNotFound() : Http::response(['created' => true], 201));

    (new PersistentVolumeClaim($org_server->organization, $org_server))->create('5Gi');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === RANCHER_API.'/v1/persistentvolumeclaims'
        && $request['spec']['storageClassName'] === 'longhorn');
});

it('reads a claim\'s state from Rancher\'s metadata.state', function (array $body, int $expected) {
    $org_server = steveOrgServer();
    Http::fake(['*' => Http::response($body)]);

    expect((new PersistentVolumeClaim($org_server->organization, $org_server))->isActive())->toBe($expected);
})->with([
    'bound' => [rancherState('bound'), 1],
    'binding' => [rancherState('pending', true), 2],
    'active is not bound' => [rancherState('active'), 0],
]);

it('creates a job through Rancher\'s batch.jobs collection', function () {
    $org_server = steveOrgServer();
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? rancherNotFound() : Http::response(['metadata' => ['name' => 'demo-job-abc']], 201));

    $result = (new Job($org_server->organization, $org_server))->create(jobChart());

    expect($result['status'])->toBe('success');
    expect($result['response']['metadata']['name'])->toBe('demo-job-abc');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === RANCHER_API.'/v1/batch.jobs');
});

it('reads a job\'s status, treating anything unrecognised as failed', function (array $body, string $expected) {
    $org_server = steveOrgServer();
    Http::fake(['*' => Http::response($body)]);

    expect((new Job($org_server->organization, $org_server))->status('demo-job-abc'))->toBe($expected);
})->with([
    'running' => [['status' => ['active' => 1]], 'running'],
    'succeeded' => [['status' => ['succeeded' => 1]], 'success'],
    'Rancher gave up on it' => [['metadata' => ['name' => 'demo-job-abc', 'state' => ['error' => true, 'message' => 'boom']], 'status' => ['active' => 1]], 'failed'],
    'no status yet' => [['status' => []], 'failed'],
]);

it('reads pod logs through the standard Kubernetes API Rancher serves at its root', function () {
    $org_server = steveOrgServer();
    $slug = $org_server->organization->slug;

    Http::fake(function (Request $request) use ($slug) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($path === "/api/v1/namespaces/{$slug}/pods") {
            return Http::response(['items' => [['metadata' => ['name' => 'demo-job-abc-x1'], 'spec' => ['containers' => [['name' => 'scan']]]]]]);
        }

        return $path === "/api/v1/namespaces/{$slug}/pods/demo-job-abc-x1/log"
            ? Http::response("line one\nline two\n")
            : Http::response('unexpected', 500);
    });

    $logs = (new Pod($org_server->organization, $org_server))->logsForJob('demo-job-abc');

    expect($logs)->toBe("line one\nline two");

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/pods?') && str_contains($request->url(), 'labelSelector=job-name%3Ddemo-job-abc'));
});

it('installs, upgrades and uninstalls an app through Rancher\'s catalog actions', function () {
    $org_server = steveOrgServer();
    Http::fake(['*' => Http::response(['ok' => true])]);

    $application = new Application($org_server->organization, $org_server);
    $instance = demoInstance();
    $chart = helmChart();

    expect($application->create($instance, $chart)['status'])->toBe('success');
    expect($application->update($instance, $chart)['status'])->toBe('success');
    expect($application->remove($instance, $chart)['status'])->toBe('success');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === RANCHER_API.'/v1/catalog.cattle.io.clusterrepos/kumulicp-repo?action=install'
        && $request['charts'][0]['releaseName'] === 'demo-app-demo');
    Http::assertSent(fn (Request $request) => $request->url() === RANCHER_API.'/v1/catalog.cattle.io.clusterrepos/kumulicp-repo?action=upgrade');
    Http::assertSent(fn (Request $request) => $request->url() === RANCHER_API.'/v1/catalog.cattle.io.apps/demo/demo-app-demo?action=uninstall');
});

it('treats uninstalling an app that is already gone as done', function () {
    $org_server = steveOrgServer();
    Http::fake(['*' => rancherNotFound()]);

    $result = (new Application($org_server->organization, $org_server))->remove(demoInstance(), helmChart());

    expect($result['status'])->toBe('success');
});

it('reads an app\'s state from its catalog app', function (array $body, int $status, int $expected) {
    $org_server = steveOrgServer();
    Http::fake(['*' => Http::response($body, $status)]);

    expect((new Application($org_server->organization, $org_server))->isActive(demoInstance(), helmChart()))->toBe($expected);

    Http::assertSent(fn (Request $request) => $request->url() === RANCHER_API.'/v1/catalog.cattle.io.apps/demo/demo-app-demo');
})->with([
    'deployed' => [rancherState('deployed'), 200, 1],
    'installing' => [rancherState('pending-install', true), 200, 2],
    'failed' => [rancherState('failed'), 200, 3],
    'some other state' => [rancherState('superseded'), 200, 0],
    'not found' => [['type' => 'error'], 404, 0],
]);

it('retrieves the catalog app itself, as the job charts read its resources', function () {
    $org_server = steveOrgServer();
    Http::fake(['*' => Http::response(['spec' => ['resources' => [['kind' => 'PersistentVolumeClaim', 'name' => 'data']]]])]);

    $result = (new Application($org_server->organization, $org_server))->retrieve(demoInstance(), helmChart());

    expect($result['status'])->toBe('success');
    expect($result['response']['spec']['resources'][0]['name'])->toBe('data');
});

it('fails loudly when Rancher rejects a request', function () {
    $org_server = steveOrgServer();
    Http::fake(['*' => Http::response(['type' => 'error', 'status' => 422, 'message' => 'invalid'], 422)]);

    expect(fn () => (new KubernetesNamespace($org_server->organization, $org_server))->isActive())->toThrow(ConnectionFailedException::class);
});

it('fails loudly when Rancher cannot be reached', function () {
    $org_server = steveOrgServer();
    Http::fake(fn () => throw new ConnectionException('cURL error 7: connection refused'));

    expect(fn () => (new KubernetesNamespace($org_server->organization, $org_server))->isActive())->toThrow(ConnectionFailedException::class);
});

it('is the Kubernetes web interface, minus recovery of releases Rancher manages itself', function () {
    $org_server = steveOrgServer();

    $interface = new RancherWebInterface($org_server);

    expect($interface)->toBeInstanceOf(KubernetesWebInterface::class);
    expect($interface->canRecoverStuckRelease())->toBeFalse();
    expect((new KubernetesWebInterface($org_server))->canRecoverStuckRelease())->toBeTrue();
});
