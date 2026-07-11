<?php

use App\Integrations\ServerManagers\HelmKubernetes\Support\HelmCli;
use App\Server;
use Illuminate\Support\Facades\Process;

it('runs helm with the configured binary and bearer-token auth args', function () {
    Process::fake();

    $server = Server::factory()->create([
        'interface' => 'helm_k8s',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
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
