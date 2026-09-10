<?php

use App\Integrations\ServerManagers\HelmKubernetes\API\KubernetesNamespace;
use App\Organization;
use App\OrgServer;
use App\Server;
use Illuminate\Support\Facades\Process;

function makeHelmK8sOrgServer(): OrgServer
{
    $server = Server::factory()->create([
        'interface' => 'helm_k8s',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
    ]);

    $organization = Organization::factory()->create();

    return OrgServer::create([
        'organization_id' => $organization->id,
        'server_id' => $server->id,
    ]);
}

it('maps an Active namespace phase to isActive() == 1', function () {
    $org_server = makeHelmK8sOrgServer();

    Process::fake([
        '*' => Process::result(json_encode(['status' => ['phase' => 'Active']])),
    ]);

    $namespace = new KubernetesNamespace($org_server->organization, $org_server);

    expect($namespace->isActive())->toBe(1);
});

it('maps a Terminating namespace phase to isActive() == 2', function () {
    $org_server = makeHelmK8sOrgServer();

    Process::fake([
        '*' => Process::result(json_encode(['status' => ['phase' => 'Terminating']])),
    ]);

    $namespace = new KubernetesNamespace($org_server->organization, $org_server);

    expect($namespace->isActive())->toBe(2);
});

it('maps a failed kubectl call to isActive() == 0', function () {
    $org_server = makeHelmK8sOrgServer();

    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'not found', exitCode: 1),
    ]);

    $namespace = new KubernetesNamespace($org_server->organization, $org_server);

    expect($namespace->isActive())->toBe(0);
});
