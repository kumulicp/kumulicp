<?php

use App\AppInstance;
use App\Application;
use App\Integrations\ServerManagers\Rancher\API\Application as RancherApplication;
use App\Integrations\ServerManagers\Rancher\API\Job;
use App\Integrations\ServerManagers\Rancher\API\Secret;
use App\Integrations\ServerManagers\Rancher\Charts\ChartSecrets;
use App\Integrations\ServerManagers\Rancher\Charts\HelmChart;
use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const RANCHER = 'https://rancher.example.com';

function rancherOrgServer(array $overrides = []): OrgServer
{
    $server = Server::factory()->create(array_merge([
        'interface' => 'rancher',
        'address' => RANCHER,
        'api_key' => 'token-abc',
        'api_secret' => 'shhh',
        'settings' => [],
    ], $overrides));

    return OrgServer::create([
        'organization_id' => Organization::factory()->create()->id,
        'server_id' => $server->id,
    ]);
}

function rancherJobChart(string $namespace = 'demo'): JobChart
{
    $instance = (new AppInstance)->forceFill(['name' => 'demo-app']);
    $instance->setRelation('application', (new Application)->forceFill(['slug' => 'demo']));
    $org_server = new OrgServer;
    $org_server->setRelation('server', (new Server)->forceFill(['interface' => 'rancher']));
    $instance->setRelation('web_server', $org_server);

    $chart = new JobChart((new Organization)->forceFill(['slug' => 'demo']), $instance);
    $chart->chart = [
        'apiVersion' => 'batch/v1',
        'kind' => 'Job',
        'metadata' => ['name' => 'demo-job-abc', 'namespace' => $namespace],
        'spec' => ['template' => ['spec' => ['containers' => []]]],
    ];

    return $chart;
}

// Rancher's own API (/v1/...) next to the Kubernetes API it proxies
// (/k8s/clusters/<id>/...), both in memory. Writes go to $log as
// "METHOD kind:name". $steve_uid is the uid Rancher's create-Job response
// carries (null: it omits it). $job_lookup is the proxy's answer when the Job
// is looked up afterwards.
function fakeRancher(?string $steve_uid = 'job-uid-1', int $job_lookup = 200, bool $steve_fails = false): ArrayObject
{
    $store = new ArrayObject;
    $log = new ArrayObject;

    Http::fake(function (Request $request) use ($store, $log, $steve_uid, $job_lookup, $steve_fails) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $body = json_decode($request->body(), true) ?? [];

        if ($path === '/v1/batch.jobs') {
            $log[] = 'POST Job:'.$body['metadata']['name'].' (rancher)';

            return $steve_fails
                ? Http::response(['type' => 'error', 'status' => 500, 'message' => 'boom'], 500)
                : Http::response(array_replace_recursive($body, ['metadata' => array_filter(['uid' => $steve_uid])]), 201);
        }

        if (str_contains($path, '/v1/catalog.cattle.io.')) {
            $log[] = 'POST '.(str_contains($request->url(), 'uninstall') ? 'uninstall' : 'install');

            return Http::response(['ok' => true]);
        }

        if (str_contains($path, '/jobs/')) {
            return $job_lookup === 200
                ? Http::response(['metadata' => ['name' => 'demo-job-abc', 'uid' => 'job-uid-2']])
                : Http::response(['kind' => 'Status', 'message' => 'not found'], $job_lookup);
        }

        switch ($request->method()) {
            case 'GET':
                return isset($store[$path])
                    ? Http::response($store[$path])
                    : Http::response(['kind' => 'Status', 'message' => 'not found'], 404);

            case 'POST':
                $log[] = 'POST '.$body['kind'].':'.$body['metadata']['name'];
                $store[$path.'/'.$body['metadata']['name']] = $body;

                return Http::response($body, 201);

            case 'PATCH':
                $log[] = 'PATCH '.basename($path);
                $store[$path] = array_replace_recursive($store[$path], $body);

                return Http::response($store[$path]);

            case 'DELETE':
                $log[] = 'DELETE '.basename($path);
                unset($store[$path]);

                return Http::response(['kind' => 'Status', 'status' => 'Success']);
        }
    });

    return $log;
}

it('reaches the cluster API through Rancher\'s proxy with the Rancher API key', function (array $overrides, string $expected_base) {
    fakeRancher();

    $store = new ChartSecrets('demo', 'demo-secrets');
    $store->set('key', 'value');

    (new Secret(...rancherSecretArgs($overrides)))->applyStore($store);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === $expected_base.'/api/v1/namespaces/demo/secrets'
        && $request->hasHeader('Authorization', 'Bearer token-abc:shhh'));
})->with([
    'the local cluster by default' => [[], RANCHER.'/k8s/clusters/local'],
    'a cluster named in the settings' => [['settings' => ['rancher_cluster_id' => 'c-m-xyz']], RANCHER.'/k8s/clusters/c-m-xyz'],
    'an address that already names a cluster' => [['address' => RANCHER.'/k8s/clusters/c-m-abc/'], RANCHER.'/k8s/clusters/c-m-abc'],
]);

function rancherSecretArgs(array $overrides): array
{
    $org_server = rancherOrgServer($overrides);

    return [$org_server->organization, $org_server];
}

it('creates the Secret before the Job, then owns it by the Job', function () {
    $log = fakeRancher();

    $chart = rancherJobChart();
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');
    $name = $chart->secrets()->name();

    $org_server = rancherOrgServer();
    $result = (new Job($org_server->organization, $org_server))->create($chart);

    expect($result['status'])->toBe('success')
        ->and($log->getArrayCopy())->toBe(["POST Secret:{$name}", 'POST Job:demo-job-abc (rancher)', "PATCH {$name}"]);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && (json_decode($request->body(), true)['metadata']['ownerReferences'][0]['uid'] ?? null) === 'job-uid-1');
});

it('looks the Job up for its uid when Rancher\'s response leaves it out', function () {
    fakeRancher(steve_uid: null);

    $chart = rancherJobChart();
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');

    $org_server = rancherOrgServer();
    (new Job($org_server->organization, $org_server))->create($chart);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && (json_decode($request->body(), true)['metadata']['ownerReferences'][0]['uid'] ?? null) === 'job-uid-2');
});

it('removes the Secret when the Job is confirmed not to exist', function () {
    $log = fakeRancher(steve_uid: null, job_lookup: 404);

    $chart = rancherJobChart();
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');
    $name = $chart->secrets()->name();

    $org_server = rancherOrgServer();
    (new Job($org_server->organization, $org_server))->create($chart);

    expect($log->getArrayCopy())->toContain("DELETE {$name}");
});

it('leaves the Secret alone when the Job lookup fails for any other reason', function () {
    $log = fakeRancher(steve_uid: null, job_lookup: 500);

    $chart = rancherJobChart();
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');

    $org_server = rancherOrgServer();
    (new Job($org_server->organization, $org_server))->create($chart);

    expect(collect($log)->filter(fn ($entry) => str_starts_with($entry, 'DELETE')))->toBeEmpty();
});

it('removes the Secret when creating the Job throws', function () {
    $log = fakeRancher(steve_fails: true);

    $chart = rancherJobChart();
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');
    $name = $chart->secrets()->name();

    $org_server = rancherOrgServer();

    try {
        (new Job($org_server->organization, $org_server))->create($chart);
    } catch (Throwable) {
        // either outcome is fine: what matters is that the Secret does not linger
    }

    expect($log->getArrayCopy())->toContain("DELETE {$name}");
});

it('retargets the Secret to the namespace the Job runs in', function () {
    $log = fakeRancher();

    $chart = rancherJobChart('hub-namespace');
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');
    $name = $chart->secrets()->name();

    $org_server = rancherOrgServer();
    (new Job($org_server->organization, $org_server))->create($chart);

    // the fake files the Secret under the namespace in the request path
    expect($chart->secrets()->namespace())->toBe('hub-namespace')
        ->and($log->getArrayCopy())->toContain("POST Secret:{$name}");
});

it('makes no cluster-proxy call for a job with no secrets', function () {
    $log = fakeRancher();

    $org_server = rancherOrgServer();
    (new Job($org_server->organization, $org_server))->create(rancherJobChart());

    expect($log->getArrayCopy())->toBe(['POST Job:demo-job-abc (rancher)']);
});

function rancherHelmChart(): HelmChart
{
    $instance = (new AppInstance)->forceFill(['name' => 'demo-app']);
    $instance->setRelation('application', (new Application)->forceFill(['slug' => 'demo', 'name' => 'Demo']));

    return new class((new Organization)->forceFill(['slug' => 'demo', 'name' => 'Demo']), $instance) extends HelmChart
    {
        public $repo_name = 'repo';

        public function values(): array
        {
            return [];
        }

        // The real one records an override on the instance, which saves it
        public function chartName()
        {
            return 'demo-app-demo';
        }

        public function buildChart()
        {
            $this->secrets()->set('db-password', 'pw');

            return ['charts' => []];
        }
    };
}

it('applies the chart Secret before installing, and removes it on uninstall', function () {
    $log = fakeRancher();

    $chart = rancherHelmChart();
    $name = $chart->secrets()->name();
    $org_server = rancherOrgServer();
    $api = new RancherApplication($org_server->organization, $org_server);

    $api->create($chart->app_instance, $chart);

    expect($log->getArrayCopy())->toBe(["POST Secret:{$name}", 'POST install']);

    $api->remove($chart->app_instance, $chart);

    expect($log->getArrayCopy())->toBe(["POST Secret:{$name}", 'POST install', 'POST uninstall', "DELETE {$name}"]);
});
