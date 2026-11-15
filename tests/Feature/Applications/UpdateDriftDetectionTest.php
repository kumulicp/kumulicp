<?php

use App\AppInstance;
use App\AppPlan;
use App\AppRole;
use App\AppVersion;
use App\Console\Calls\ScanUpdateDrift;
use App\DefinitionChange;
use App\Organization;
use App\Server;
use App\Updates\Detection\DefinitionChanges;
use App\Updates\Detection\ImpactResolver;
use App\Updates\UpdateDrift;
use App\UpdateState;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;

/**
 * Edits to the plans, versions and roles that app instances depend on are
 * noticed as they are made, and only the instances they can affect are looked
 * at for what went out of date. The nightly scan finds what that missed.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

/** An active instance whose aspects are taken as up to date */
function detectionInstance(): AppInstance
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();

    $instance = test()->runActivate($plan, Organization::find(1), $app);
    app(UpdateDrift::class)->baseline($instance->fresh());

    // Nothing from setting it up counts
    DefinitionChange::query()->delete();

    return $instance->fresh();
}

function driftedAspects(AppInstance $instance): array
{
    return UpdateState::drifted()->where('app_instance_id', $instance->id)->orderBy('aspect')->pluck('aspect')->all();
}

it('notes an edit to the plan an instance uses, and what it put out of date', function () {
    $instance = detectionInstance();

    editPlan($instance->plan, 5);

    $change = DefinitionChange::sole();
    expect($change->subject_type)->toBe(AppPlan::class)
        ->and($change->subject_id)->toBe($instance->plan_id)
        ->and($change->changed)->toContain('settings')
        // (with LDAP the roles depend on the plan too)
        ->and(driftedAspects($instance))->toContain('release', 'settings');

    $state = UpdateState::drifted()->where('app_instance_id', $instance->id)->where('aspect', 'settings')->first();
    expect($state->definition_change_id)->toBe($change->id)
        ->and($state->drift_detected_at)->not->toBeNull();
});

it('does not note an edit that only changed what no instance looks like because of', function () {
    $instance = detectionInstance();

    $plan = $instance->plan;
    $plan->name = 'Renamed';
    $plan->description = 'Something else';
    $plan->display_order = 99;
    $plan->save();

    $instance->version->update_check = ['checked_at' => now()->toIso8601String()];
    $instance->version->save();

    $role = AppRole::where('application_id', $instance->application_id)->firstOrFail();
    $role->label = 'A different label';
    $role->save();

    expect(DefinitionChange::count())->toBe(0)
        ->and(driftedAspects($instance))->toBe([]);
});

it('does not note an edit to a plan that no instance uses', function () {
    $instance = detectionInstance();

    $unused = AppPlan::factory()->create(['application_id' => $instance->application_id, 'settings' => ['base' => ['max' => 1]]]);
    editPlan($unused, 2);

    expect(DefinitionChange::count())->toBe(0);
});

it('looks only at the instances that use what was edited', function () {
    $instance = detectionInstance();
    $other = AppPlan::factory()->create(['application_id' => $instance->application_id]);
    $resolver = app(ImpactResolver::class);

    expect($resolver->targets(AppPlan::class, $instance->plan_id, $instance->application_id)->pluck('id')->all())->toBe([$instance->id])
        ->and($resolver->targets(AppPlan::class, $other->id, $instance->application_id)->exists())->toBeFalse()
        ->and($resolver->targets(AppVersion::class, $instance->version_id, null)->pluck('id')->all())->toBe([$instance->id])
        // Every install of the app uses its roles
        ->and($resolver->targets(AppRole::class, 1, $instance->application_id)->pluck('id')->all())->toBe([$instance->id])
        ->and($resolver->targets(Server::class, 1, null))->toBeNull();
});

it('notes an edit to the version and flags only the release', function () {
    $instance = detectionInstance();

    $instance->version->updateSettings(['chart_version' => '9.9.9']);

    expect(DefinitionChange::sole()->subject_label)->toContain($instance->version->name)
        ->and(driftedAspects($instance))->toBe(['release']);
});

it('notes an edit to a role and flags the roles', function () {
    config(['account_manager.driver' => 'ldap']);
    $instance = detectionInstance();

    $role = AppRole::where('application_id', $instance->application_id)->firstOrFail();
    $role->ignore_role = ! $role->ignore_role;
    $role->save();

    expect(DefinitionChange::sole()->subject_type)->toBe(AppRole::class)
        ->and(driftedAspects($instance))->toBe(['roles']);
});

it('notes the implied roles of a role changing, which is not a change to the role', function () {
    config(['account_manager.driver' => 'ldap']);
    $instance = detectionInstance();
    $role = AppRole::where('application_id', $instance->application_id)->firstOrFail();

    $recorded = app(DefinitionChanges::class)->record($role, 'updated', ['implied_roles']);

    expect($recorded->changed)->toBe(['implied_roles']);
});

it('forgets what is out of date when an edit is undone', function () {
    $instance = detectionInstance();
    $plan = $instance->plan;
    $before = $plan->settings;

    editPlan($plan, 5);
    expect(driftedAspects($instance))->toContain('settings');

    $plan->settings = $before;
    $plan->save();

    expect(driftedAspects($instance))->toBe([])
        ->and(UpdateState::where('app_instance_id', $instance->id)->whereNotNull('drift_detected_at')->exists())->toBeFalse();
});

it('keeps the time it first went out of date, and the edit that did it, through further edits', function () {
    $instance = detectionInstance();

    editPlan($instance->plan, 5);
    $first = UpdateState::drifted()->where('aspect', 'settings')->first();

    $this->travel(2)->hours();
    editPlan($instance->fresh()->plan, 6);
    $again = UpdateState::drifted()->where('aspect', 'settings')->first();

    expect($again->drift_detected_at->equalTo($first->drift_detected_at))->toBeTrue()
        ->and($again->definition_change_id)->toBe($first->definition_change_id)
        ->and(DefinitionChange::count())->toBe(2);
});

it('is up to date again once what was applied is stored', function () {
    $instance = detectionInstance();
    $drift = app(UpdateDrift::class);
    editPlan($instance->plan, 5);

    $drift->record($instance->fresh(), $drift->current($instance->fresh(), ['settings']));

    expect(driftedAspects($instance))->toContain('release')->not->toContain('settings');
});

it('finds, with the nightly scan, what an edit made without the models changed', function () {
    $instance = detectionInstance();

    DB::table('app_plans')->where('id', $instance->plan_id)->update(['settings' => json_encode(['base' => ['storage' => 99], 'additionalConfigs' => ['greeting' => ['aspect' => 'settings']], 'configurations' => ['greeting' => 99]])]);

    expect(DefinitionChange::count())->toBe(0)
        ->and(driftedAspects($instance))->toBe([]);

    (new ScanUpdateDrift(now: true))();

    expect(driftedAspects($instance))->toContain('release', 'settings')
        // no edit was seen, so none is blamed
        ->and(UpdateState::drifted()->where('app_instance_id', $instance->id)->whereNotNull('definition_change_id')->exists())->toBeFalse();
});

it('does not look at the instances of test organizations', function () {
    $instance = detectionInstance();
    DB::table('organizations')->where('id', $instance->organization_id)->update(['account_test_id' => 1]);

    editPlan($instance->plan, 5);

    expect(DefinitionChange::count())->toBe(0)
        ->and(driftedAspects($instance))->toBe([]);
});

it('lists what is out of date with a command', function () {
    $instance = detectionInstance();
    editPlan($instance->plan, 5);

    $this->artisan('updates:scan')->expectsOutputToContain('settings')->assertSuccessful();

    app(UpdateDrift::class)->dismiss($instance->fresh());

    $this->artisan('updates:scan')->expectsOutput('Everything is up to date.')->assertSuccessful();
});
