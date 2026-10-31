<?php

use App\AppInstance;
use App\Application;
use App\Integrations\ServerManagers\Kubernetes\API\Job;
use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function jobChartFor(string $interface, string $job_namespace = 'demo'): JobChart
{
    $instance = (new AppInstance)->forceFill(['name' => 'demo-app']);
    $instance->setRelation('application', (new Application)->forceFill(['slug' => 'demo']));
    $org_server = new OrgServer;
    $org_server->setRelation('server', (new Server)->forceFill(['interface' => $interface]));
    $instance->setRelation('web_server', $org_server);

    $chart = new JobChart((new Organization)->forceFill(['slug' => 'demo']), $instance);
    $chart->chart = [
        'apiVersion' => 'batch/v1',
        'kind' => 'Job',
        'metadata' => ['name' => 'demo-job-abc', 'namespace' => $job_namespace],
    ];

    return $chart;
}

function jobApi(): Job
{
    $server = Server::factory()->create([
        'interface' => 'kubernetes',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
    ]);

    $org_server = OrgServer::create([
        'organization_id' => Organization::factory()->create()->id,
        'server_id' => $server->id,
    ]);

    return new Job($org_server->organization, $org_server);
}

// A tiny in-memory Kubernetes API: GET returns what was POSTed/PATCHed (404
// otherwise), POST assigns a uid (the Job's is 'job-uid-1'), PATCH merges and
// DELETE removes. $reject answers the POST of a kind with a 403.
function fakeJobCluster(array $reject = []): void
{
    $store = new ArrayObject;

    Http::fake(function (Request $request) use ($store, $reject) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $body = json_decode($request->body(), true) ?? [];

        switch ($request->method()) {
            case 'GET':
                return isset($store[$path])
                    ? Http::response($store[$path])
                    : Http::response(['kind' => 'Status', 'message' => 'not found'], 404);

            case 'POST':
                if (in_array($body['kind'], $reject, true)) {
                    return Http::response(['kind' => 'Status', 'message' => 'denied'], 403);
                }

                $body['metadata']['uid'] = $body['kind'] === 'Job' ? 'job-uid-1' : 'uid-'.$body['metadata']['name'];
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
}

// "<Kind>:<name>" of each manifest created or updated, in order.
function appliedManifests(): array
{
    return Http::recorded()
        ->filter(fn ($pair) => in_array($pair[0]->method(), ['POST', 'PATCH']))
        ->map(fn ($pair) => ($m = json_decode($pair[0]->body(), true)) ? $m['kind'].':'.$m['metadata']['name'] : null)
        ->values()
        ->all();
}

it('reads an env secret from the Secret on kubernetes, never inlining the value', function () {
    $chart = jobChartFor('kubernetes');

    $env = $chart->secretEnv('NO_REPLY_PASSWORD', 'super-secret-value');

    expect($env['name'])->toBe('NO_REPLY_PASSWORD')
        ->and($env['valueFrom']['secretKeyRef'])->toBe(['name' => $chart->secrets()->name(), 'key' => 'no-reply-password'])
        ->and(json_encode($env))->not->toContain('super-secret-value')
        ->and(base64_decode($chart->secrets()->manifest()['data']['no-reply-password']))->toBe('super-secret-value');
});

it('keeps a plain env value on drivers that do not create the Secret', function () {
    $chart = jobChartFor('other');

    expect($chart->secretEnv('NO_REPLY_PASSWORD', 'pw'))->toBe(['name' => 'NO_REPLY_PASSWORD', 'value' => 'pw'])
        ->and($chart->secrets()->isEmpty())->toBeTrue();
});

it('gives each job instance its own secret store', function () {
    expect(jobChartFor('kubernetes')->secrets()->name())->not->toBe(jobChartFor('kubernetes')->secrets()->name());
});

it('creates the Secret before the Job, then owns it by the Job', function () {
    fakeJobCluster();

    $chart = jobChartFor('kubernetes');
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');
    $secret_name = $chart->secrets()->name();

    $result = jobApi()->create($chart);

    expect($result['status'])->toBe('success')
        ->and(appliedManifests())->toBe(["Secret:{$secret_name}", 'Job:demo-job-abc', "Secret:{$secret_name}"]);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && (json_decode($request->body(), true)['metadata']['ownerReferences'][0]['uid'] ?? null) === 'job-uid-1');
});

it('retargets the Secret to the namespace the Job runs in', function () {
    fakeJobCluster();

    $chart = jobChartFor('kubernetes', 'hub-namespace');
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');

    jobApi()->create($chart);

    expect($chart->secrets()->namespace())->toBe('hub-namespace');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/namespaces/hub-namespace/secrets'));
});

it('removes the Secret again if the Job could not be created', function () {
    fakeJobCluster(reject: ['Job']);

    $chart = jobChartFor('kubernetes');
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');

    $result = jobApi()->create($chart);

    expect($result['status'])->toBe('failed');
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/secrets/'.$chart->secrets()->name()));
});

it('does not apply anything extra for a job with no secrets', function () {
    fakeJobCluster();

    jobApi()->create(jobChartFor('kubernetes'));

    expect(appliedManifests())->toBe(['Job:demo-job-abc']);
});

it('labels the Secret like the Job it belongs to', function () {
    fakeJobCluster();

    $chart = jobChartFor('kubernetes');
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');

    jobApi()->create($chart);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && ($m = json_decode($request->body(), true))['kind'] === 'Secret'
        && $m['metadata']['labels']['kumulicp.io/app'] === 'demo'
        && $m['metadata']['labels']['kumulicp.io/instance'] === 'demo-app'
        && $m['metadata']['labels']['kumulicp.io/type'] === 'job'
        && $m['metadata']['labels']['app.kubernetes.io/managed-by'] === 'kumulicp');
});

// A cluster whose create-Job response leaves the uid out; GETs of the Job
// answer $lookup (200 with a uid, or an error status) once it has been created.
function fakeJobClusterWithoutUid(int $lookup = 200): void
{
    $job_created = false;

    Http::fake(function (Request $request) use (&$job_created, $lookup) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $body = json_decode($request->body(), true) ?? [];

        if (str_ends_with($path, '/jobs/demo-job-abc')) {
            return $job_created && $lookup === 200
                ? Http::response(['metadata' => ['name' => 'demo-job-abc', 'uid' => 'job-uid-2']])
                : Http::response(['kind' => 'Status', 'message' => 'not found'], $job_created ? $lookup : 404);
        }

        if ($request->method() === 'POST' && ($body['kind'] ?? null) === 'Job') {
            $job_created = true;
        }

        return $request->method() === 'GET'
            ? Http::response(['kind' => 'Status', 'message' => 'not found'], 404)
            : Http::response($body, 201);
    });
}

it('looks the Job up for its uid when the create response leaves it out', function () {
    fakeJobClusterWithoutUid();

    $chart = jobChartFor('kubernetes');
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');

    jobApi()->create($chart);

    Http::assertSent(fn (Request $request) => in_array($request->method(), ['POST', 'PATCH'])
        && (json_decode($request->body(), true)['metadata']['ownerReferences'][0]['uid'] ?? null) === 'job-uid-2');
});

it('removes the Secret when the Job is confirmed not to exist', function () {
    fakeJobClusterWithoutUid(lookup: 404);

    $chart = jobChartFor('kubernetes');
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');

    jobApi()->create($chart);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/secrets/'.$chart->secrets()->name()));
});

it('leaves the Secret alone when the Job lookup fails for any other reason', function () {
    fakeJobClusterWithoutUid(lookup: 500);

    $chart = jobChartFor('kubernetes');
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');

    jobApi()->create($chart);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
});

it('reaches the cluster API through the same client as everything else', function () {
    $api = jobApi();

    expect($api->clusterApi())->toBe($api->api());
});
