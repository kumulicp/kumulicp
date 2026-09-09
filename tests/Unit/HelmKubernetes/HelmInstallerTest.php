<?php

use App\Integrations\ServerManagers\HelmKubernetes\API\HelmInstaller;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Support\Facades\Process;

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

// Note: Process::fake()'s array keys support wildcard (Str::is()) matching,
// but Process::assertRan()/assertRanTimes()/assertNotRan() do NOT -- a
// plain string there is compared with strict equality against the whole
// $process->command array, so a literal '*apply*' string never matches
// anything. Assertions below use closures inspecting $process->command
// (and ->input, for the piped manifest JSON) instead.
function isApplyCommand($process): bool
{
    return in_array('apply', (array) $process->command, true);
}

function isDeleteCommand($process, string $kind): bool
{
    return in_array('delete', (array) $process->command, true)
        && in_array($kind, (array) $process->command, true);
}

// Returns the applied manifest's own metadata.name in the response, and a
// uid only for the Job -- mirroring how kubectl apply -o json actually
// echoes back the created/updated object.
function fakeApplyReturningUid(string $uid): \Closure
{
    return function ($process) use ($uid) {
        $manifest = json_decode($process->input, true);
        $metadata = ['name' => $manifest['metadata']['name'] ?? 'x'];

        if (($manifest['kind'] ?? null) === 'Job') {
            $metadata['uid'] = $uid;
        }

        return Process::result(json_encode(['metadata' => $metadata]));
    };
}

it('creates the Job and returns success once it is accepted, without waiting for it to finish', function () {
    Process::fake([
        '*apply*' => fakeApplyReturningUid('job-uid-123'),
    ]);

    $installer = makeHelmInstaller();
    $result = $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n");

    expect($result['success'])->toBeTrue();
    expect($result['output'])->toContain('nextcloud-5iy7z');
    expect($result['exit_code'])->toBe(0);

    Process::assertNotRan(fn ($process) => in_array('get', (array) $process->command, true));
    Process::assertNotRan(fn ($process) => in_array('logs', (array) $process->command, true));
});

it('attaches an ownerReference to the values ConfigMap once the Job uid is known', function () {
    Process::fake([
        '*apply*' => fakeApplyReturningUid('job-uid-123'),
    ]);

    $installer = makeHelmInstaller();
    $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n");

    Process::assertRan(function ($process) {
        $manifest = json_decode($process->input, true);

        return ($manifest['kind'] ?? null) === 'ConfigMap'
            && ($manifest['metadata']['ownerReferences'][0]['uid'] ?? null) === 'job-uid-123'
            && ($manifest['metadata']['ownerReferences'][0]['kind'] ?? null) === 'Job';
    });
});

it('never creates a values ConfigMap or credentials Secret for a plain uninstall', function () {
    Process::fake([
        '*apply*' => fakeApplyReturningUid('job-uid-123'),
    ]);

    $installer = makeHelmInstaller();
    $result = $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['uninstall', 'nextcloud-5iy7z', '--ignore-not-found']);

    expect($result['success'])->toBeTrue();
    Process::assertRanTimes(fn ($process) => isApplyCommand($process), 1);
});

it('wires credentials into a Secret via envFrom and attaches its ownerReference too', function () {
    Process::fake([
        '*apply*' => fakeApplyReturningUid('job-uid-123'),
    ]);

    $installer = makeHelmInstaller();
    $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n", [
        'HELM_REPO_USERNAME' => 'deploy',
        'HELM_REPO_PASSWORD' => 'secret',
    ]);

    // ConfigMap + Secret + Job, then ConfigMap + Secret re-applied with owner refs.
    Process::assertRanTimes(fn ($process) => isApplyCommand($process), 5);

    Process::assertRan(function ($process) {
        $manifest = json_decode($process->input, true);

        return ($manifest['kind'] ?? null) === 'Secret'
            && ($manifest['metadata']['ownerReferences'][0]['uid'] ?? null) === 'job-uid-123';
    });
});

it('bails out without creating the Job if the values ConfigMap apply fails', function () {
    Process::fake([
        '*apply*' => Process::result(output: '', errorOutput: 'namespace not found', exitCode: 1),
    ]);

    $installer = makeHelmInstaller();
    $result = $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n");

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('namespace not found');

    Process::assertRanTimes(fn ($process) => isApplyCommand($process), 1);
});

it('rolls back the ConfigMap if the credentials Secret apply fails', function () {
    Process::fake([
        '*apply*' => function ($process) {
            $manifest = json_decode($process->input, true);

            if (($manifest['kind'] ?? null) === 'Secret') {
                return Process::result(output: '', errorOutput: 'forbidden', exitCode: 1);
            }

            return Process::result(json_encode(['metadata' => ['name' => $manifest['metadata']['name'] ?? 'x']]));
        },
        '*delete*' => Process::result(''),
    ]);

    $installer = makeHelmInstaller();
    $result = $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n", [
        'HELM_REPO_USERNAME' => 'deploy',
        'HELM_REPO_PASSWORD' => 'secret',
    ]);

    expect($result['success'])->toBeFalse();

    // ConfigMap (succeeded) + Secret (failed) -- never reaches the Job apply.
    Process::assertRanTimes(fn ($process) => isApplyCommand($process), 2);
    Process::assertRan(fn ($process) => isDeleteCommand($process, 'configmap'));
});

it('rolls back the ConfigMap and Secret if the Job apply itself fails', function () {
    Process::fake([
        '*apply*' => function ($process) {
            $manifest = json_decode($process->input, true);

            if (($manifest['kind'] ?? null) === 'Job') {
                return Process::result(output: '', errorOutput: 'forbidden', exitCode: 1);
            }

            return Process::result(json_encode(['metadata' => ['name' => $manifest['metadata']['name'] ?? 'x']]));
        },
        '*delete*' => Process::result(''),
    ]);

    $installer = makeHelmInstaller();
    $result = $installer->create('kumuli-demo', 'nextcloud-5iy7z', ['upgrade', '--install'], "replicaCount: 1\n", [
        'HELM_REPO_USERNAME' => 'deploy',
        'HELM_REPO_PASSWORD' => 'secret',
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe('forbidden');

    Process::assertRan(fn ($process) => isDeleteCommand($process, 'configmap'));
    Process::assertRan(fn ($process) => isDeleteCommand($process, 'secret'));
});
