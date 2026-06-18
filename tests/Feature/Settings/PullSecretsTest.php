<?php

use App\AppVersion;
use App\PullSecret;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    $support = new TestSupports;
    $support->seed();

    $this->support = $support;

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($this->user);
});

it('creates a pull secret', function () {
    $response = $this->post('/admin/settings/pull-secrets', [
        'name' => 'my-registry',
        'registry' => 'docker.example.com',
        'username' => 'deploy',
        'password' => 'secret-password',
    ]);

    $response->assertSessionDoesntHaveErrors();

    $pull_secret = PullSecret::where('name', 'my-registry')->firstOrFail();

    expect($pull_secret->registry)->toBe('docker.example.com');
    expect($pull_secret->username)->toBe('deploy');
    expect($pull_secret->password)->toBe('secret-password');
    expect($pull_secret->requiresAuth())->toBeTrue();
});

it('allows a pull secret without credentials for public registries', function () {
    $response = $this->post('/admin/settings/pull-secrets', [
        'name' => 'public-registry',
        'registry' => 'docker.io',
    ]);

    $response->assertSessionDoesntHaveErrors();

    $pull_secret = PullSecret::where('name', 'public-registry')->firstOrFail();

    expect($pull_secret->requiresAuth())->toBeFalse();
});

it('requires a unique name', function () {
    PullSecret::factory()->create(['name' => 'existing']);

    $response = $this->post('/admin/settings/pull-secrets', [
        'name' => 'existing',
        'registry' => 'docker.example.com',
    ]);

    $response->assertSessionHasErrors('name');
});

it('deletes a pull secret that is not in use', function () {
    $pull_secret = PullSecret::factory()->create();

    $response = $this->delete('/admin/settings/pull-secrets/'.$pull_secret->id);

    $response->assertSessionDoesntHaveErrors();
    expect(PullSecret::find($pull_secret->id))->toBeNull();
});

it('prevents deleting a pull secret in use by an app instance', function () {
    $this->support->activateDemoApp();

    $pull_secret = PullSecret::factory()->create();

    $app_instance = $this->support->demo_app->instances()->first();
    $app_instance->version->pull_secret_id = $pull_secret->id;
    $app_instance->version->save();

    $response = $this->delete('/admin/settings/pull-secrets/'.$pull_secret->id);

    $response->assertSessionHas('error');
    expect(PullSecret::find($pull_secret->id))->not->toBeNull();
});

it('mass migrates app versions from one pull secret to another', function () {
    $this->support->activateDemoApp();

    $from = PullSecret::factory()->create();
    $to = PullSecret::factory()->create();

    $version = AppVersion::factory()->create([
        'application_id' => $this->support->demo_app->id,
        'pull_secret_id' => $from->id,
    ]);

    $response = $this->post('/admin/settings/pull-secrets/mass-migrate', [
        'from_id' => $from->id,
        'to_id' => $to->id,
    ]);

    $response->assertSessionDoesntHaveErrors();

    $version->refresh();
    expect($version->pull_secret_id)->toBe($to->id);
});
