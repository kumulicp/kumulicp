<?php

use App\AppVersion;
use App\RepoSecret;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    $support = new TestSupports;
    $support->seed();

    $this->support = $support;

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($this->user);
});

it('creates an image repo secret', function () {
    $response = $this->post('/admin/settings/repo-secrets', [
        'type' => 'image',
        'name' => 'my-registry',
        'registry' => 'docker.example.com',
        'username' => 'deploy',
        'password' => 'secret-password',
    ]);

    $response->assertSessionDoesntHaveErrors();

    $repo_secret = RepoSecret::where('name', 'my-registry')->firstOrFail();

    expect($repo_secret->type)->toBe('image');
    expect($repo_secret->registry)->toBe('docker.example.com');
    expect($repo_secret->username)->toBe('deploy');
    expect($repo_secret->password)->toBe('secret-password');
    expect($repo_secret->requiresAuth())->toBeTrue();
});

it('creates a helm repo secret', function () {
    $response = $this->post('/admin/settings/repo-secrets', [
        'type' => 'helm',
        'name' => 'my-chart-repo',
        'registry' => 'https://charts.example.com',
        'username' => 'deploy',
        'password' => 'secret-password',
    ]);

    $response->assertSessionDoesntHaveErrors();

    $repo_secret = RepoSecret::where('name', 'my-chart-repo')->firstOrFail();

    expect($repo_secret->type)->toBe('helm');
});

it('allows a repo secret without credentials for public registries', function () {
    $response = $this->post('/admin/settings/repo-secrets', [
        'type' => 'image',
        'name' => 'public-registry',
        'registry' => 'docker.io',
    ]);

    $response->assertSessionDoesntHaveErrors();

    $repo_secret = RepoSecret::where('name', 'public-registry')->firstOrFail();

    expect($repo_secret->requiresAuth())->toBeFalse();
});

it('requires a unique name', function () {
    RepoSecret::factory()->create(['name' => 'existing']);

    $response = $this->post('/admin/settings/repo-secrets', [
        'type' => 'image',
        'name' => 'existing',
        'registry' => 'docker.example.com',
    ]);

    $response->assertSessionHasErrors('name');
});

it('deletes a repo secret that is not in use', function () {
    $repo_secret = RepoSecret::factory()->create();

    $response = $this->delete('/admin/settings/repo-secrets/'.$repo_secret->id);

    $response->assertSessionDoesntHaveErrors();
    expect(RepoSecret::find($repo_secret->id))->toBeNull();
});

it('prevents deleting a repo secret in use by an app instance', function () {
    $this->support->activateDemoApp();

    $repo_secret = RepoSecret::factory()->create();

    $app_instance = $this->support->demo_app->instances()->first();
    $app_instance->version->pull_secret_id = $repo_secret->id;
    $app_instance->version->save();

    $response = $this->delete('/admin/settings/repo-secrets/'.$repo_secret->id);

    $response->assertSessionHas('error');
    expect(RepoSecret::find($repo_secret->id))->not->toBeNull();
});

it('mass migrates app versions from one repo secret to another', function () {
    $this->support->activateDemoApp();

    $from = RepoSecret::factory()->create();
    $to = RepoSecret::factory()->create();

    $version = AppVersion::factory()->create([
        'application_id' => $this->support->demo_app->id,
        'pull_secret_id' => $from->id,
    ]);

    $response = $this->post('/admin/settings/repo-secrets/mass-migrate', [
        'from_id' => $from->id,
        'to_id' => $to->id,
    ]);

    $response->assertSessionDoesntHaveErrors();

    $version->refresh();
    expect($version->pull_secret_id)->toBe($to->id);
});

it('mass migrates versions on both columns for the both type', function () {
    $this->support->activateDemoApp();

    $from = RepoSecret::factory()->both()->create();
    $to = RepoSecret::factory()->both()->create();

    $pull_version = AppVersion::factory()->create([
        'application_id' => $this->support->demo_app->id,
        'pull_secret_id' => $from->id,
    ]);
    $helm_version = AppVersion::factory()->create([
        'application_id' => $this->support->demo_app->id,
        'helm_repo_secret_id' => $from->id,
    ]);

    $response = $this->post('/admin/settings/repo-secrets/mass-migrate', [
        'from_id' => $from->id,
        'to_id' => $to->id,
    ]);

    $response->assertSessionDoesntHaveErrors();

    expect($pull_version->refresh()->pull_secret_id)->toBe($to->id);
    expect($helm_version->refresh()->helm_repo_secret_id)->toBe($to->id);
});

it('rejects mass migrating between mismatched secret types', function () {
    $image_secret = RepoSecret::factory()->create();
    $helm_secret = RepoSecret::factory()->helm()->create();

    $response = $this->post('/admin/settings/repo-secrets/mass-migrate', [
        'from_id' => $image_secret->id,
        'to_id' => $helm_secret->id,
    ]);

    $response->assertSessionHas('error');
});
