<?php

use App\AppInstance;
use App\Support\Facades\Application;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Applications\VolumeFixtures as Fixtures;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * The page that shows an app's Helm values: each chart's own, and what a release is given.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    setupAccountManagerDriver('db');
    (new TestSupports)->seed();

    $this->admin = User::find(1);
    $this->appInstance = Fixtures::instance('nextcloud');
    $this->url = "/admin/organizations/{$this->appInstance->organization_id}/apps/{$this->appInstance->id}/helm-values";
});

it('shows, for each chart, its own values and what a release is given', function () {
    $this->actingAs($this->admin)->get($this->url)->assertInertia(fn ($page) => $page
        ->component('Admin/Organizations/Apps/AppHelmValues')
        ->has('charts', 1)
        ->where('charts.0.name', fn ($name) => is_string($name) && $name !== '')
        ->has('charts.0.values.persistence')
        ->has('charts.0.deployed.persistence')
        // What the chart does not build: the labels, which a release is given
        ->missing('charts.0.values.podLabels')
        ->has('charts.0.deployed.podLabels')
    );
});

it('masks the secrets in both', function () {
    $password = Application::instance($this->appInstance)->api_password();

    $response = $this->actingAs($this->admin)->get($this->url);

    $response->assertInertia(fn ($page) => $page
        ->where('charts.0.values.nextcloud.password', '••••••••')
        ->where('charts.0.deployed.nextcloud.password', '••••••••')
    );

    expect($password)->not->toBeEmpty()
        ->and($response->getContent())->not->toContain($password);
});

it('writes nothing when it is opened', function () {
    $settings = AppInstance::find($this->appInstance->id)->settings;

    // Building the chart would give the release a name
    expect($settings)->not->toHaveKey('override');

    $this->actingAs($this->admin)->get($this->url)->assertOk();

    expect(AppInstance::find($this->appInstance->id)->settings)->toBe($settings);
});
