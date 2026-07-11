<?php

use App\Integrations\ServerManagers\HelmKubernetes\Support\HelmCli;
use App\Server;
use Illuminate\Support\Facades\Process;

it('runs helm with the configured binary and bearer-token auth args', function () {
    Process::fake();

    $server = Server::factory()->create([
        'interface' => 'helm_k8s',
        'k8s_api_server' => 'https://cluster.example.com:6443',
        'k8s_ca_cert' => 'fake-ca',
        'k8s_auth_type' => 'bearer_token',
        'k8s_bearer_token' => 'token',
    ]);

    $cli = new HelmCli($server);
    $result = $cli->run(['status', 'my-release', '-o', 'json'], 'demo');

    expect($result['success'])->toBeTrue();

    Process::assertRan(function ($process) {
        $command = $process->command;

        return $command[0] === 'helm'
            && in_array('status', $command)
            && in_array('my-release', $command)
            && in_array('--kube-token', $command);
    });
});
