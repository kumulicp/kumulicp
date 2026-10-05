<?php

use App\AppInstance;
use App\Integrations\ServerManagers\HelmKubernetes\API\Job;
use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Support\Facades\Process;

function jobChartFor(string $interface, string $job_namespace = 'demo'): JobChart
{
    $instance = (new AppInstance)->forceFill(['name' => 'demo-app']);
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
        'interface' => 'helm_k8s',
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

// Fakes every kubectl call with $respond and records "<Kind>:<name>" of each
// manifest piped to `kubectl apply`, in order, into the returned ArrayObject.
function recordApplies(?Closure $respond = null): ArrayObject
{
    $applied = new ArrayObject;

    Process::fake(function ($process) use ($applied, $respond) {
        $manifest = json_decode((string) $process->input, true);

        if (in_array('apply', (array) $process->command, true) && $manifest) {
            $applied[] = $manifest['kind'].':'.$manifest['metadata']['name'];
        }

        return $respond
            ? $respond($manifest)
            : Process::result(json_encode(['metadata' => ['name' => $manifest['metadata']['name'] ?? 'x', 'uid' => 'uid-1']]));
    });

    return $applied;
}

it('reads an env secret from the Secret on helm_k8s, never inlining the value', function () {
    $chart = jobChartFor('helm_k8s');

    $env = $chart->secretEnv('NO_REPLY_PASSWORD', 'super-secret-value');

    expect($env['name'])->toBe('NO_REPLY_PASSWORD')
        ->and($env['valueFrom']['secretKeyRef'])->toBe(['name' => $chart->secrets()->name(), 'key' => 'no-reply-password'])
        ->and(json_encode($env))->not->toContain('super-secret-value')
        ->and(base64_decode($chart->secrets()->manifest()['data']['no-reply-password']))->toBe('super-secret-value');
});

it('keeps a plain env value on drivers that do not create the Secret', function () {
    $chart = jobChartFor('rancher');

    expect($chart->secretEnv('NO_REPLY_PASSWORD', 'pw'))->toBe(['name' => 'NO_REPLY_PASSWORD', 'value' => 'pw'])
        ->and($chart->secrets()->isEmpty())->toBeTrue();
});

it('gives each job instance its own secret store', function () {
    expect(jobChartFor('helm_k8s')->secrets()->name())->not->toBe(jobChartFor('helm_k8s')->secrets()->name());
});

it('creates the Secret before the Job, then owns it by the Job', function () {
    $applied = recordApplies(fn ($manifest) => Process::result(json_encode(['metadata' => [
        'name' => $manifest['metadata']['name'],
        'uid' => 'job-uid-1',
    ]])));

    $chart = jobChartFor('helm_k8s');
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');
    $secret_name = $chart->secrets()->name();

    $result = jobApi()->create($chart);

    expect($result['status'])->toBe('success')
        ->and($applied->getArrayCopy())->toBe(["Secret:{$secret_name}", 'Job:demo-job-abc', "Secret:{$secret_name}"]);

    Process::assertRan(fn ($process) => ($m = json_decode((string) $process->input, true))
        && ($m['kind'] ?? null) === 'Secret'
        && ($m['metadata']['ownerReferences'][0]['uid'] ?? null) === 'job-uid-1');
});

it('retargets the Secret to the namespace the Job runs in', function () {
    recordApplies();

    $chart = jobChartFor('helm_k8s', 'hub-namespace');
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');

    jobApi()->create($chart);

    expect($chart->secrets()->namespace())->toBe('hub-namespace');
    Process::assertRan(fn ($process) => ($m = json_decode((string) $process->input, true))
        && ($m['kind'] ?? null) === 'Secret'
        && $m['metadata']['namespace'] === 'hub-namespace');
});

it('removes the Secret again if the Job could not be created', function () {
    Process::fake(function ($process) {
        $manifest = json_decode((string) $process->input, true);

        return ($manifest['kind'] ?? null) === 'Job'
            ? Process::result(output: '', errorOutput: 'denied', exitCode: 1)
            : Process::result(json_encode(['metadata' => ['name' => 'x']]));
    });

    $chart = jobChartFor('helm_k8s');
    $chart->secretEnv('ACCOUNT_TOKEN', 'tok');

    $result = jobApi()->create($chart);

    expect($result['status'])->toBe('failed');
    Process::assertRan(fn ($process) => in_array('delete', (array) $process->command, true)
        && in_array('secret', (array) $process->command, true)
        && in_array($chart->secrets()->name(), (array) $process->command, true));
});

it('does not apply anything extra for a job with no secrets', function () {
    $applied = recordApplies();

    jobApi()->create(jobChartFor('helm_k8s'));

    expect($applied->getArrayCopy())->toBe(['Job:demo-job-abc']);
});
