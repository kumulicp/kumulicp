<?php

use App\Application;
use App\AppVersion;
use App\Integrations\ServerManagers\Rancher\API\Secret;
use App\RepoSecret;

it('builds a k8s secret name from its id', function () {
    $repo_secret = RepoSecret::factory()->create();

    expect($repo_secret->k8sSecretName())->toBe('pull-secret-'.$repo_secret->id);
});

it('reports whether authentication is required', function () {
    $with_auth = RepoSecret::factory()->create(['username' => 'deploy', 'password' => 'secret']);
    $without_auth = RepoSecret::factory()->create(['username' => null, 'password' => null]);

    expect($with_auth->requiresAuth())->toBeTrue();
    expect($without_auth->requiresAuth())->toBeFalse();
});

it('builds a valid dockerconfigjson payload', function () {
    $repo_secret = RepoSecret::factory()->create([
        'registry' => 'docker.example.com',
        'username' => 'deploy',
        'password' => 'secret',
    ]);

    $config = json_decode($repo_secret->dockerConfigJson(), true);

    expect($config['auths']['docker.example.com']['username'])->toBe('deploy');
    expect($config['auths']['docker.example.com']['password'])->toBe('secret');
    expect($config['auths']['docker.example.com']['auth'])->toBe(base64_encode('deploy:secret'));
});

it('encrypts the password attribute', function () {
    $repo_secret = RepoSecret::factory()->create(['password' => 'secret']);

    expect($repo_secret->getRawOriginal('password'))->not->toBe('secret');
    expect($repo_secret->password)->toBe('secret');
});

it('defaults to the image type', function () {
    $repo_secret = RepoSecret::factory()->create();

    expect($repo_secret->type)->toBe(RepoSecret::TYPE_IMAGE);
});

it('scopes versions() by type', function () {
    $image_secret = RepoSecret::factory()->create();
    $helm_secret = RepoSecret::factory()->helm()->create();
    $application = Application::factory()->create();

    AppVersion::factory()->create(['application_id' => $application->id, 'image_registry_id' => $image_secret->id]);
    AppVersion::factory()->create(['application_id' => $application->id, 'helm_repo_secret_id' => $helm_secret->id]);

    expect($image_secret->versions()->count())->toBe(1);
    expect($helm_secret->versions()->count())->toBe(1);
});

it('matches versions() via either column for the both type', function () {
    $both_secret = RepoSecret::factory()->both()->create();
    $application = Application::factory()->create();

    AppVersion::factory()->create(['application_id' => $application->id, 'image_registry_id' => $both_secret->id]);
    AppVersion::factory()->create(['application_id' => $application->id, 'helm_repo_secret_id' => $both_secret->id]);

    expect($both_secret->versions()->count())->toBe(2);
});

it('labels its cluster secret as a pull secret for the registry, not an app or instance', function () {
    $repo_secret = RepoSecret::factory()->create();

    expect($repo_secret->k8sLabels())->toBe([
        'kumulicp.io/type' => 'pull-secret',
        'kumulicp.io/registry' => (string) $repo_secret->id,
    ]);
});

it('applies the labels to the secret in both server managers', function (string $secret_class, string $method) {
    $repo_secret = RepoSecret::factory()->create();

    $secret = (new ReflectionClass($secret_class))->newInstanceWithoutConstructor();
    $builder = new ReflectionMethod($secret_class, $method);

    $secret_data = $builder->invoke($secret, 'some-namespace', $repo_secret);

    expect($secret_data['metadata']['labels'])->toBe($repo_secret->k8sLabels());
})->with([
    'rancher' => [Secret::class, 'values'],
    'kubernetes' => [App\Integrations\ServerManagers\Kubernetes\API\Secret::class, 'manifest'],
]);
