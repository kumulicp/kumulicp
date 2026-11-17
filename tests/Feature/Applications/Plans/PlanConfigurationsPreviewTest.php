<?php

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Organization;
use App\Support\ExampleAppInstance;
use App\Support\Facades\Application as ApplicationFacade;
use App\User;
use Illuminate\Support\Facades\Facade;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->application = ApplicationFacade::initialize('generic');
    ApplicationFacade::roles($this->application);

    $this->plan = AppPlan::factory()->create([
        'application_id' => $this->application->id,
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
        'application_id' => $this->application->id,
        'settings' => [
            'chart_name' => 'generic',
            'chart_version' => '3.1.5',
            'image_repo_name' => 'library/my-app',
        ],
    ]);

    $this->previewUrl = "/admin/apps/{$this->application->slug}/plans/{$this->plan->id}/configurations/preview";
});

function activateGenericInstance($test, ?AppPlan $plan = null): AppInstance
{
    $instance = ApplicationFacade::activate(Organization::factory()->create(), $test->application, $test->version, $plan ?? $test->plan)->get();
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
        'application_id' => $this->application->id,
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

describe('example instance', function () {
    beforeEach(function () {
        $this->exampleUrl = $this->previewUrl.'/example';
    });

    it('generates an example on the latest version, even when it is not enabled', function () {
        $this->version->forceFill(['status' => 'active'])->save();
        $latest = AppVersion::factory()->create([
            'application_id' => $this->application->id,
            'status' => 'disabled',
            'settings' => ['chart_name' => 'generic', 'chart_version' => '3.2.0', 'image_repo_name' => 'library/my-app'],
        ]);

        $this->actingAs($this->user)->postJson($this->exampleUrl, [
            'configurations' => ['resources-requests-cpu' => '500m'],
        ])->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('instance.example', true)
            ->assertJsonPath('charts.0.values.resources.requests.cpu', '500m');

        $example = AppInstance::where('application_id', $this->application->id)->sole();

        expect($example->status)->toBe(AppInstance::STATUS_EXAMPLE)
            ->and($example->version_id)->toBe($latest->id)
            ->and($example->organization->type)->toBe(Organization::TYPE_EXAMPLE);
    });

    it('keeps using the example for the normal preview', function () {
        $this->actingAs($this->user)->postJson($this->exampleUrl, ['configurations' => []])->assertOk();
        // A real request starts with nothing memoized from the one before
        app()->forgetScopedInstances();
        Facade::clearResolvedInstances();

        $this->actingAs($this->user)->postJson($this->previewUrl, ['configurations' => ['resources-requests-cpu' => '750m']])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('instance.example', true)
            ->assertJsonPath('charts.0.values.resources.requests.cpu', '750m');
    });

    it('regenerates rather than piling up examples', function () {
        $this->actingAs($this->user)->postJson($this->exampleUrl, ['configurations' => []])->assertOk();
        $this->actingAs($this->user)->postJson($this->exampleUrl, ['configurations' => []])->assertOk();

        expect(AppInstance::where('application_id', $this->application->id)->count())->toBe(1)
            ->and(Organization::where('type', Organization::TYPE_EXAMPLE)->count())->toBe(1);
    });

    it('prefers a real instance over the example', function () {
        $this->actingAs($this->user)->postJson($this->exampleUrl, ['configurations' => []])->assertOk();
        $real = AppInstance::where('status', AppInstance::STATUS_EXAMPLE)->sole();
        $real->delete();
        ExampleAppInstance::generate($this->application, $this->plan);

        $instance = ApplicationFacade::activate(Organization::factory()->create(), $this->application, $this->version, $this->plan)->get();
        $instance->status = 'activating';
        $instance->save();

        $this->actingAs($this->user)->postJson($this->previewUrl, ['configurations' => []])
            ->assertOk()
            ->assertJsonPath('instance.example', false);
    });

    it('is cleared out when the first instance becomes active', function () {
        $this->actingAs($this->user)->postJson($this->exampleUrl, ['configurations' => []])->assertOk();

        activateGenericInstance($this);

        expect(AppInstance::where('status', AppInstance::STATUS_EXAMPLE)->exists())->toBeFalse()
            ->and(Organization::where('type', Organization::TYPE_EXAMPLE)->exists())->toBeFalse();
    });

    it('leaves the example of other apps alone', function () {
        $other = Application::factory()->create();
        $other_plan = AppPlan::factory()->create(['application_id' => $other->id, 'settings' => $this->plan->settings]);
        $other_version = AppVersion::factory()->create(['application_id' => $other->id]);
        ExampleAppInstance::generate($other, $other_plan);

        activateGenericInstance($this);

        expect(AppInstance::where('application_id', $other->id)->where('status', AppInstance::STATUS_EXAMPLE)->exists())->toBeTrue();
    });

    it('is kept out of the running apps and real organizations', function () {
        $this->actingAs($this->user)->postJson($this->exampleUrl, ['configurations' => []])->assertOk();

        expect(AppInstance::notDeactivated()->where('application_id', $this->application->id)->exists())->toBeFalse()
            ->and(Organization::notTest()->where('type', Organization::TYPE_EXAMPLE)->exists())->toBeFalse();
    });

    it('renders the example as an enabled app even if its organization was deactivated', function () {
        $this->actingAs($this->user)->postJson($this->exampleUrl, ['configurations' => []])->assertOk();
        Organization::where('type', Organization::TYPE_EXAMPLE)->update(['status' => 'deactivated']);

        // Generating again puts it right
        $this->actingAs($this->user)->postJson($this->exampleUrl, ['configurations' => []])->assertOk();

        expect(Organization::where('type', Organization::TYPE_EXAMPLE)->sole()->status)->toBe('active');
    });

    it('says so when the app has no version to make an example from', function () {
        $this->version->delete();

        $this->actingAs($this->user)->postJson($this->exampleUrl, ['configurations' => []])
            ->assertOk()
            ->assertJsonPath('status', 'no_version');

        expect(AppInstance::count())->toBe(0);
    });
});
