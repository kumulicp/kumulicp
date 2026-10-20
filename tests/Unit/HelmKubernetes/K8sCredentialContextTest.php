<?php

use App\Integrations\ServerManagers\HelmKubernetes\Support\K8sCredentialContext;
use App\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeK8sServer(array $attributes = []): Server
{
    return Server::factory()->create(array_merge([
        'interface' => 'helm_k8s',
        'address' => 'https://cluster.example.com:6443',
        'ca_cert' => 'fake-ca',
        'api_secret' => 'token',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
    ], $attributes));
}

it('writes an ephemeral 0600 CA file for bearer-token auth and cleans it up', function () {
    $server = makeK8sServer([
        'ca_cert' => "-----BEGIN CERTIFICATE-----\nfake\n-----END CERTIFICATE-----",
        'api_secret' => 'super-secret-token',
        'settings' => ['k8s_auth_type' => 'bearer_token', 'k8s_tls_verify' => 'true'],
    ]);

    $context = new K8sCredentialContext($server);
    $capturedPath = null;

    $context->withCredentialFiles(function (array $options) use (&$capturedPath, $server) {
        $capturedPath = $options['verify'];

        expect($capturedPath)->toBeString();
        expect(file_exists($capturedPath))->toBeTrue();
        expect(substr(sprintf('%o', fileperms($capturedPath)), -4))->toBe('0600');
        expect(file_get_contents($capturedPath))->toBe($server->ca_cert);
        expect($options)->not->toHaveKeys(['cert', 'ssl_key']);
    });

    expect(file_exists($capturedPath))->toBeFalse();
});

it('cleans up the ephemeral file even if the callback throws', function () {
    $context = new K8sCredentialContext(makeK8sServer());
    $capturedPath = null;

    try {
        $context->withCredentialFiles(function (array $options) use (&$capturedPath) {
            $capturedPath = $options['verify'];

            throw new Exception('boom');
        });
    } catch (Exception $e) {
        expect($e->getMessage())->toBe('boom');
    }

    expect($capturedPath)->toBeString();
    expect(file_exists($capturedPath))->toBeFalse();
});

it('writes the client certificate and key to ephemeral files for client-cert auth', function () {
    $server = makeK8sServer([
        'api_key' => 'fake-key',
        'api_secret' => 'fake-cert',
        'settings' => ['k8s_auth_type' => 'client_cert'],
    ]);

    $context = new K8sCredentialContext($server);
    expect($context->usesClientCert())->toBeTrue();

    $paths = [];

    $context->withCredentialFiles(function (array $options) use (&$paths) {
        $paths = [$options['cert'], $options['ssl_key']];

        expect(file_get_contents($options['cert']))->toBe('fake-cert');
        expect(file_get_contents($options['ssl_key']))->toBe('fake-key');
        expect(substr(sprintf('%o', fileperms($options['ssl_key'])), -4))->toBe('0600');
    });

    foreach ($paths as $path) {
        expect(file_exists($path))->toBeFalse();
    }
});

it('skips the CA file and disables verification when k8s_tls_verify is false', function () {
    $server = makeK8sServer(['settings' => ['k8s_auth_type' => 'bearer_token', 'k8s_tls_verify' => 'false']]);

    (new K8sCredentialContext($server))->withCredentialFiles(function (array $options) {
        expect($options['verify'])->toBeFalse();
    });
});

it('falls back to the system trust store when no CA cert is set', function () {
    $server = makeK8sServer(['ca_cert' => null]);

    (new K8sCredentialContext($server))->withCredentialFiles(function (array $options) {
        expect($options['verify'])->toBeTrue();
    });
});

it('defaults k8s_tls_verify to true when unset', function () {
    $server = Server::factory()->create([
        'interface' => 'helm_k8s',
        'settings' => ['k8s_auth_type' => 'bearer_token'],
    ]);

    expect((new K8sCredentialContext($server))->tlsVerify())->toBeTrue();
});

it('sends bearer-token requests to the API server address with an Authorization header', function () {
    Http::fake();

    $context = new K8sCredentialContext(makeK8sServer(['address' => 'https://cluster.example.com:6443/']));
    $context->withRequest(fn ($http) => $http->get('/version'));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://cluster.example.com:6443/version'
        && $request->hasHeader('Authorization', 'Bearer token'));
});

it('sends no bearer token for client-cert auth', function () {
    Http::fake();

    $server = makeK8sServer([
        'api_key' => 'fake-key',
        'api_secret' => 'fake-cert',
        'settings' => ['k8s_auth_type' => 'client_cert'],
    ]);

    (new K8sCredentialContext($server))->withRequest(fn ($http) => $http->get('/version'));

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
});

it('adds impersonation headers when configured', function () {
    Http::fake();

    $server = makeK8sServer(['settings' => [
        'k8s_auth_type' => 'bearer_token',
        'k8s_impersonate_user' => 'deploy-bot',
        'k8s_impersonate_group' => 'kumulicp',
    ]]);

    (new K8sCredentialContext($server))->withRequest(fn ($http) => $http->get('/version'));

    Http::assertSent(fn (Request $request) => $request->hasHeader('Impersonate-User', 'deploy-bot')
        && $request->hasHeader('Impersonate-Group', 'kumulicp'));
});
