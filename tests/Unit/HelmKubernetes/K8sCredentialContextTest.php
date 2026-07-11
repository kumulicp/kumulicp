<?php

use App\Integrations\ServerManagers\HelmKubernetes\Support\K8sCredentialContext;
use App\Server;

it('writes an ephemeral 0600 CA file for bearer-token auth and cleans it up', function () {
    $server = Server::factory()->create([
        'interface' => 'helm_k8s',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => "-----BEGIN CERTIFICATE-----\nfake\n-----END CERTIFICATE-----",
        'api_secret' => 'super-secret-token',
        'settings' => ['k8s_auth_type' => 'bearer_token', 'k8s_tls_verify' => 'true'],
    ]);

    $context = new K8sCredentialContext($server);
    $capturedPath = null;

    $context->withAuthArgs('demo', function (array $helm_args) use (&$capturedPath, $server) {
        $ca_index = array_search('--kube-ca-file', $helm_args);
        expect($ca_index)->not->toBeFalse();

        $capturedPath = $helm_args[$ca_index + 1];

        expect(file_exists($capturedPath))->toBeTrue();
        expect(substr(sprintf('%o', fileperms($capturedPath)), -4))->toBe('0600');
        expect(file_get_contents($capturedPath))->toBe($server->ca_cert);

        expect($helm_args)->toContain('--kube-apiserver', $server->address);
        expect($helm_args)->toContain('--kube-token', 'super-secret-token');
    });

    expect(file_exists($capturedPath))->toBeFalse();
});

it('cleans up the ephemeral file even if the callback throws', function () {
    $server = Server::factory()->create([
        'interface' => 'helm_k8s',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
    ]);

    $context = new K8sCredentialContext($server);
    $capturedPath = null;

    try {
        $context->withAuthArgs('demo', function (array $helm_args) use (&$capturedPath) {
            $ca_index = array_search('--kube-ca-file', $helm_args);
            $capturedPath = $helm_args[$ca_index + 1];

            throw new Exception('boom');
        });
    } catch (Exception $e) {
        expect($e->getMessage())->toBe('boom');
    }

    expect(file_exists($capturedPath))->toBeFalse();
});

it('builds an inline kubeconfig for client-cert auth using api_key/api_secret as key/cert', function () {
    $server = Server::factory()->create([
        'interface' => 'helm_k8s',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_key' => 'fake-key',
        'api_secret' => 'fake-cert',
        'settings' => ['k8s_auth_type' => 'client_cert'],
    ]);

    $context = new K8sCredentialContext($server);
    expect($context->needsKubeconfig())->toBeTrue();

    $context->withAuthArgs('demo', function (array $helm_args) {
        expect($helm_args)->toContain('--kubeconfig');
        $path = $helm_args[array_search('--kubeconfig', $helm_args) + 1];

        expect(file_exists($path))->toBeTrue();
        $contents = file_get_contents($path);
        expect($contents)->toContain('client-certificate-data');
        expect($contents)->toContain('client-key-data');
    });
});

it('defaults k8s_tls_verify to true when unset', function () {
    $server = Server::factory()->create([
        'interface' => 'helm_k8s',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
    ]);

    expect((new K8sCredentialContext($server))->tlsVerify())->toBeTrue();
});
