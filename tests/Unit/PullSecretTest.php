<?php

use App\PullSecret;

it('builds a k8s secret name from its id', function () {
    $pull_secret = PullSecret::factory()->create();

    expect($pull_secret->k8sSecretName())->toBe('pull-secret-'.$pull_secret->id);
});

it('reports whether authentication is required', function () {
    $with_auth = PullSecret::factory()->create(['username' => 'deploy', 'password' => 'secret']);
    $without_auth = PullSecret::factory()->create(['username' => null, 'password' => null]);

    expect($with_auth->requiresAuth())->toBeTrue();
    expect($without_auth->requiresAuth())->toBeFalse();
});

it('builds a valid dockerconfigjson payload', function () {
    $pull_secret = PullSecret::factory()->create([
        'registry' => 'docker.example.com',
        'username' => 'deploy',
        'password' => 'secret',
    ]);

    $config = json_decode($pull_secret->dockerConfigJson(), true);

    expect($config['auths']['docker.example.com']['username'])->toBe('deploy');
    expect($config['auths']['docker.example.com']['password'])->toBe('secret');
    expect($config['auths']['docker.example.com']['auth'])->toBe(base64_encode('deploy:secret'));
});

it('encrypts the password attribute', function () {
    $pull_secret = PullSecret::factory()->create(['password' => 'secret']);

    expect($pull_secret->getRawOriginal('password'))->not->toBe('secret');
    expect($pull_secret->password)->toBe('secret');
});
