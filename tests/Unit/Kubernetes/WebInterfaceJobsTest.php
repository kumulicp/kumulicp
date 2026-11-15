<?php

// The web interface is how actions run a Job on whichever driver a server
// uses, so the namespace they pass has to reach the cluster.

use App\AppInstance;
use App\Application;
use App\Contracts\ServerManager\JobInterface;
use App\Integrations\ServerManagers\Kubernetes\Charts\Job\JobChart;
use App\Integrations\ServerManagers\Kubernetes\Interfaces\KubernetesWebInterface;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\Support\Facades\Application as ApplicationFacade;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function jobsOrgServer(): OrgServer
{
    $server = Server::factory()->create([
        'interface' => 'kubernetes',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
    ]);

    return OrgServer::create([
        'organization_id' => Organization::factory()->create()->id,
        'server_id' => $server->id,
    ]);
}

function jobsInterface(): KubernetesWebInterface
{
    return new KubernetesWebInterface(jobsOrgServer());
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

// A shared-plan child has no release of its own: what happens to it is applied
// by rebuilding its parent's release (see AppInstance::sharedPlanParent()).
it('rebuilds the parent\'s release instead of deploying a shared-plan child\'s own', function (string $method) {
    $parent = (new AppInstance)->forceFill(['name' => 'hub']);

    $parent_server = Mockery::mock();
    $parent_server->shouldReceive('update')->once()->andReturn('parent-updated');
    $parent_instance = Mockery::mock();
    $parent_instance->shouldReceive('connect')->with('web')->andReturn($parent_server);
    ApplicationFacade::shouldReceive('instance')->with($parent)->andReturn($parent_instance);

    $child = Mockery::mock(AppInstance::class)->makePartial();
    $child->shouldReceive('sharedPlanParent')->andReturn($parent);
    $child->shouldReceive('save')->andReturn(true);

    Http::fake();

    expect((new KubernetesWebInterface(jobsOrgServer(), $child))->$method())->toBe('parent-updated');

    Http::assertNothingSent();
})->with(['add', 'update', 'delete']);

it('marks a deleted shared-plan child deactivated before its parent is rebuilt', function () {
    $parent = (new AppInstance)->forceFill(['name' => 'hub']);
    $child = Mockery::mock(AppInstance::class)->makePartial();
    $child->shouldReceive('sharedPlanParent')->andReturn($parent);
    $child->shouldReceive('save')->once()->andReturn(true);

    $parent_server = Mockery::mock();
    $parent_server->shouldReceive('update')->once()->andReturnUsing(function () use ($child) {
        expect($child->status)->toBe('deactivated');
    });
    $parent_instance = Mockery::mock();
    $parent_instance->shouldReceive('connect')->with('web')->andReturn($parent_server);
    ApplicationFacade::shouldReceive('instance')->with($parent)->andReturn($parent_instance);

    (new KubernetesWebInterface(jobsOrgServer(), $child))->delete();
});
