<?php

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Organization;
use App\Support\Facades\Application as ApplicationFacade;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->app = ApplicationFacade::initialize('generic');
    ApplicationFacade::roles($this->app);

    $this->plan = AppPlan::factory()->create([
        'application_id' => $this->app->id,
        'settings' => [
            'base' => ['price' => 0, 'storage' => 2, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
            'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
            'configurations' => [
                'env' => ['FOO' => 'bar'],
                'resources-requests-cpu' => '250m',
            ],
        ],
    ]);

    $this->version = AppVersion::factory()->create([
        'application_id' => $this->app->id,
        'settings' => [
            'chart_name' => 'generic',
            'chart_version' => '3.1.5',
            'image_repo_name' => 'library/my-app',
        ],
    ]);

    $this->previewUrl = "/admin/apps/{$this->app->slug}/plans/{$this->plan->id}/configurations/preview";
});

function activateGenericInstance($test, ?AppPlan $plan = null): AppInstance
{
    $instance = ApplicationFacade::activate(Organization::factory()->create(), $test->app, $test->version, $plan ?? $test->plan)->get();
    $instance->status = 'active';
    $instance->save();

    return $instance;
}

it('renders the chart values with the unsaved configurations', function () {
    activateGenericInstance($this);

    $response = $this->actingAs($this->user)->postJson($this->previewUrl, [
        'configurations' => [
            'env' => ['FOO' => 'baz'],
            'resources-requests-cpu' => '500m',
        ],
    ]);

    $response->assertOk()->assertJsonPath('status', 'ok');

    $values = $response->json('charts.0.values');
    expect($values['env'])->toContain(['name' => 'FOO', 'value' => 'baz'])
        ->and($values['env'])->not->toContain(['name' => 'FOO', 'value' => 'bar'])
        ->and($values['resources']['requests']['cpu'])->toBe('500m');
});

it('does not save anything while previewing', function () {
    $instance = activateGenericInstance($this);
    $plan_settings = $this->plan->fresh()->settings;
    $instance_settings = $instance->fresh()->settings;

    $this->actingAs($this->user)->postJson($this->previewUrl, [
        'configurations' => ['env' => ['FOO' => 'baz'], 'resources-requests-cpu' => '500m'],
        'additionalConfigs' => [],
    ])->assertOk();

    expect($this->plan->fresh()->settings)->toBe($plan_settings)
        ->and($instance->fresh()->settings)->toBe($instance_settings);
});

it('applies configs added to the plan', function () {
    activateGenericInstance($this);

    $response = $this->actingAs($this->user)->postJson($this->previewUrl, [
        'configurations' => [
            'env' => ['FOO' => 'bar'],
            'nodeSelector-disk' => 'ssd',
        ],
        'additionalConfigs' => [
            'nodeSelector-disk' => ['name' => 'nodeSelector-disk', 'type' => 'string', 'persistent' => false],
        ],
    ]);

    $response->assertOk()->assertJsonPath('charts.0.values.nodeSelector.disk', 'ssd');
});

it('falls back to another instance of the app when the plan has no subscribers', function () {
    $other_plan = AppPlan::factory()->create([
        'application_id' => $this->app->id,
        'settings' => $this->plan->settings,
    ]);
    activateGenericInstance($this, $other_plan);

    $this->actingAs($this->user)->postJson($this->previewUrl, [
        'configurations' => ['resources-requests-cpu' => '750m'],
    ])->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('charts.0.values.resources.requests.cpu', '750m');
});

it('says so when there is no instance to render an example from', function () {
    $this->actingAs($this->user)->postJson($this->previewUrl, [
        'configurations' => ['resources-requests-cpu' => '500m'],
    ])->assertOk()
        ->assertJsonPath('status', 'no_instance')
        ->assertJsonPath('charts', []);
});

it('redirects guests away from the preview', function () {
    $this->postJson($this->previewUrl, ['configurations' => []])->assertUnauthorized();
});
