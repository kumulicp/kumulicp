<?php

use App\Integrations\ServerManagers\HelmKubernetes\API\HelmInstaller;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

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

it('reports success once the Job reports succeeded, and never sleeps once already done', function () {
    Sleep::fake(syncWithCarbon: true);

    Process::fake([
        '*apply*' => Process::result(json_encode(['metadata' => ['name' => 'x']])),
        '*get*job*' => Process::result(json_encode(['status' => ['succeeded' => 1]])),
        '*logs*' => Process::result('helm output: deployed'),
        '*delete*' => Process::result(''),
    ]);

    $installer = makeHelmInstaller();
    $result = $installer->runAndWait('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n");

    expect($result['success'])->toBeTrue();
    expect($result['output'])->toBe('helm output: deployed');
    expect($result['exit_code'])->toBe(0);

    Sleep::assertNeverSlept();
});

it('polls until the Job reports failed, then returns the pod logs as the error', function () {
    Sleep::fake(syncWithCarbon: true);

    Process::fake([
        '*apply*' => Process::result(json_encode(['metadata' => ['name' => 'x']])),
        '*get*job*' => Process::sequence()
            ->push(json_encode(['status' => ['active' => 1]]))
            ->push(json_encode(['status' => ['active' => 1]]))
            ->push(json_encode(['status' => ['failed' => 1]])),
        '*logs*' => Process::result('Error: could not render templates'),
        '*delete*' => Process::result(''),
    ]);

    $installer = makeHelmInstaller();
    $result = $installer->runAndWait('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n");

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe('Error: could not render templates');

    Sleep::assertSlept(fn ($duration) => $duration->total('seconds') === 5.0, times: 2);
});

it('gives up and reports failure once the deadline passes without the Job ever finishing', function () {
    Sleep::fake(syncWithCarbon: true);

    Process::fake([
        '*apply*' => Process::result(json_encode(['metadata' => ['name' => 'x']])),
        '*get*job*' => Process::result(json_encode(['status' => ['active' => 1]])),
        '*logs*' => Process::result(''),
        '*delete*' => Process::result(''),
    ]);

    $installer = makeHelmInstaller();
    $result = $installer->runAndWait('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n", null, timeoutSeconds: 20);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('did not complete within 20s');
});

it('bails out without creating the Job if the values ConfigMap apply fails', function () {
    Sleep::fake(syncWithCarbon: true);

    Process::fake([
        '*apply*' => Process::result(output: '', errorOutput: 'namespace not found', exitCode: 1),
    ]);

    $installer = makeHelmInstaller();
    $result = $installer->runAndWait('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n");

    expect($result['success'])->toBeFalse();

    Process::assertNotRan('*get*job*');
});
