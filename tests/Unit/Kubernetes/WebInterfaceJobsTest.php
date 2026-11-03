<?php

// The web interface is how actions run a Job on whichever driver a server
// uses, so the namespace they pass has to reach the cluster.

use App\AppInstance;
use App\Application;
use App\Contracts\ServerManager\JobInterface;
use App\Integrations\ServerManagers\Kubernetes\Interfaces\KubernetesWebInterface;
use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function jobsInterface(): KubernetesWebInterface
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

    return new KubernetesWebInterface($org_server);
}

// A Job manifest that doesn't say where it runs, so the namespace comes from the caller.
function jobsChart(): JobChart
{
    $instance = (new AppInstance)->forceFill(['name' => 'demo-app']);
    $instance->setRelation('application', (new Application)->forceFill(['slug' => 'demo']));

    $chart = new JobChart((new Organization)->forceFill(['slug' => 'demo']), $instance);
    $chart->chart = [
        'apiVersion' => 'batch/v1',
        'kind' => 'Job',
        'metadata' => ['name' => 'demo-job-abc'],
    ];

    return $chart;
}

function jobsNotFound()
{
    return Http::response(['kind' => 'Status', 'message' => 'not found'], 404);
}

it('is how actions run jobs', function () {
    expect(jobsInterface())->toBeInstanceOf(JobInterface::class);
});

it('runs a job in the namespace it is given', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? jobsNotFound() : Http::response(['metadata' => ['name' => 'demo-job-abc']], 201));

    $result = jobsInterface()->runJob(jobsChart(), 'hub');

    expect($result['status'])->toBe('success');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://cluster.example.com:6443/apis/batch/v1/namespaces/hub/jobs');
});

it('runs a job in the organization\'s own namespace when none is given', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? jobsNotFound() : Http::response(['metadata' => ['name' => 'demo-job-abc']], 201));

    $interface = jobsInterface();
    $interface->runJob(jobsChart());

    $slug = (fn () => $this->organization->slug)->call($interface);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === "https://cluster.example.com:6443/apis/batch/v1/namespaces/{$slug}/jobs");
});

it('reports a job that could not be created as failed rather than throwing', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? jobsNotFound() : Http::response(['kind' => 'Status', 'message' => 'denied'], 403));

    $result = jobsInterface()->runJob(jobsChart(), 'hub');

    expect($result['status'])->toBe('failed')
        ->and($result['response'])->toBe('denied');
});

it('reads a job\'s status from the namespace it is given', function () {
    Http::fake(['*' => Http::response(['status' => ['succeeded' => 1]])]);

    expect(jobsInterface()->jobStatus('demo-job-abc', 'hub'))->toBe('success');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://cluster.example.com:6443/apis/batch/v1/namespaces/hub/jobs/demo-job-abc');
});

it('reads a job\'s logs from the namespace it is given', function () {
    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($path === '/api/v1/namespaces/hub/pods') {
            return Http::response(['items' => [['metadata' => ['name' => 'demo-job-abc-x1'], 'spec' => ['containers' => [['name' => 'scan']]]]]]);
        }

        return $path === '/api/v1/namespaces/hub/pods/demo-job-abc-x1/log'
            ? Http::response("scan output\n")
            : Http::response('unexpected', 500);
    });

    expect(jobsInterface()->jobLogs('demo-job-abc', 'hub'))->toBe('scan output');
});

it('has no logs for a job with no pod', function () {
    Http::fake(['*' => Http::response(['items' => []])]);

    expect(jobsInterface()->jobLogs('demo-job-abc', 'hub'))->toBeNull();
});
