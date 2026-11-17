<?php

use App\AppInstance;
use App\AppPlan;
use App\AppRole;
use App\AppVersion;
use App\AppVersionImage;
use App\Console\Calls\ScanUpdateDrift;
use App\DefinitionChange;
use App\Organization;
use App\Server;
use App\Updates\Detection\DefinitionChanges;
use App\Updates\Aspects\ReleaseAspect;
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

it('notes an edit to the prices of the plan an instance uses, and flags the subscription', function () {
    $instance = detectionInstance();

    $instance->plan->syncPrices(['base' => ['USD' => ['amount' => 9, 'price_id' => 'price_base']]]);

    $change = DefinitionChange::sole();
    expect($change->subject_type)->toBe(AppPlan::class)
        ->and($change->subject_id)->toBe($instance->plan_id)
        ->and($change->changed)->toContain('prices')
        ->and(driftedAspects($instance))->toContain('subscription');
});

it('does not note saving the prices a plan already has', function () {
    $instance = detectionInstance();
    $prices = ['base' => ['USD' => ['amount' => 9, 'price_id' => 'price_base']]];
    $instance->plan->syncPrices($prices);
    DefinitionChange::query()->delete();

    $instance->plan->syncPrices($prices);

    expect(DefinitionChange::count())->toBe(0);
});

it('notes a change to the price of a feature', function () {
    $instance = detectionInstance();

    $instance->plan->syncFeaturePrices(['deck' => ['status' => 'optional', 'price' => 2, 'price_id' => 'price_deck']]);

    expect(DefinitionChange::sole()->changed)->toContain('prices')
        ->and(driftedAspects($instance))->toContain('subscription');
});

it('forgets what is out of date when a price edit is undone', function () {
    $instance = detectionInstance();

    $instance->plan->syncPrices(['base' => ['USD' => ['amount' => 9, 'price_id' => 'price_base']]]);
    expect(driftedAspects($instance))->toContain('subscription');

    // A plan with no prices has the fingerprint it had before it had any
    $instance->plan->syncPrices(['base' => ['USD' => ['amount' => null, 'price_id' => null]]]);

    expect(driftedAspects($instance))->not->toContain('subscription');
});

it('does not note the prices of a plan that no instance uses', function () {
    $instance = detectionInstance();
    $unused = AppPlan::factory()->create(['application_id' => $instance->application_id]);

    $unused->syncPrices(['base' => ['USD' => ['amount' => 9, 'price_id' => 'price_base']]]);

    expect(DefinitionChange::count())->toBe(0);
});

// ─── A version's images ──────────────────────────────────────────────────────

function addVersionImage(AppInstance $instance, array $attributes = []): AppVersionImage
{
    return AppVersionImage::factory()->create($attributes + [
        'app_version_id' => $instance->version_id,
        'key' => 'imaginary',
        'repository' => 'nextcloud/aio-imaginary',
        'tag' => '20261109_101109',
        'values_path' => 'imaginary.image',
    ]);
}

it('notes an image added to the version an instance runs and flags only the release', function () {
    $instance = detectionInstance();

    addVersionImage($instance);

    $change = DefinitionChange::sole();

    expect($change->subject_type)->toBe(AppVersion::class)
        ->and($change->subject_id)->toBe($instance->version_id)
        ->and($change->changed)->toBe(['images'])
        ->and(driftedAspects($instance))->toBe(['release']);
});

it('notes a change to what an image runs, and an image being removed', function () {
    $instance = detectionInstance();
    $image = addVersionImage($instance);
    app(UpdateDrift::class)->dismiss($instance->fresh());
    DefinitionChange::query()->delete();

    $image->update(['tag' => '20270101_000000']);

    expect(DefinitionChange::sole()->changed)->toBe(['images'])
        ->and(driftedAspects($instance))->toBe(['release']);

    app(UpdateDrift::class)->dismiss($instance->fresh());
    DefinitionChange::query()->delete();

    $image->delete();

    expect(DefinitionChange::sole()->changed)->toBe(['images'])
        ->and(driftedAspects($instance))->toBe(['release']);
});

it('does not note an edit to what only changes how an image is shown or checked for updates', function () {
    $instance = detectionInstance();
    $image = addVersionImage($instance);
    app(UpdateDrift::class)->dismiss($instance->fresh());
    DefinitionChange::query()->delete();

    $image->update(['label' => 'Imaginary', 'position' => 4, 'update_policy' => 'pinned', 'tag_pattern' => '^\d+$', 'group' => 'media']);

    expect(DefinitionChange::count())->toBe(0)
        ->and(driftedAspects($instance))->toBe([]);
});

it('notes the primary image following the version\'s settings once, as the version\'s change', function () {
    $instance = detectionInstance();
    $instance->version->updateSettings(['image_repo_name' => 'library/demo']);
    app(UpdateDrift::class)->dismiss($instance->fresh());
    DefinitionChange::query()->delete();

    $instance->version->updateSettings(['image_repo_name' => 'library/other']);

    $change = DefinitionChange::sole();

    expect($change->changed)->toContain('settings')->not->toContain('images')
        ->and($instance->version->fresh()->primaryImage()->repository)->toBe('library/other')
        ->and(driftedAspects($instance))->toBe(['release']);
});

it('does not flag an instance for an image the chart ships or a job runs', function () {
    $instance = detectionInstance();

    addVersionImage($instance, ['key' => 'shipped', 'source' => 'chart']);
    addVersionImage($instance, ['key' => 'migrate', 'role' => 'job']);

    // The edits were looked at, and found nothing that is deployed differently
    expect(driftedAspects($instance))->toBe([]);
});

it('does not look at the instances of other versions when an image is added', function () {
    $instance = detectionInstance();
    $other = AppVersion::factory()->create(['application_id' => $instance->application_id, 'name' => '9.9']);

    AppVersionImage::factory()->create(['app_version_id' => $other->id, 'key' => 'imaginary']);

    expect(DefinitionChange::count())->toBe(0)
        ->and(driftedAspects($instance))->toBe([]);
});

it('keeps the release fingerprint of an instance whose version only has the image its settings describe', function () {
    $instance = detectionInstance();
    $instance->version->updateSettings(['image_repo_name' => 'library/demo']);

    $aspect = new ReleaseAspect;
    $instance = $instance->fresh();

    expect($instance->version->images)->toHaveCount(1)
        ->and($aspect->fingerprint($instance))->toBe($aspect->fingerprint($instance, false));
});

it('stamps the release of an instance whose version already had images, so it is not flagged for it', function () {
    $instance = detectionInstance();

    // The image was there before images were part of the release (no edit was seen)
    DB::table('app_version_images')->insert([
        'app_version_id' => $instance->version_id, 'key' => 'imaginary', 'role' => 'component', 'source' => 'override',
        'registry' => 'docker.io', 'repository' => 'nextcloud/aio-imaginary', 'tag' => '20261109_101109',
        'values_path' => 'imaginary.image', 'tag_from_version_name' => false, 'update_policy' => 'track', 'position' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(UpdateDrift::class)->drift($instance->fresh()))->toBe(['release']);

    (require base_path('database/migrations/2026_11_17_000000_restamp_fingerprints_for_version_images.php'))->up();

    expect(app(UpdateDrift::class)->drift($instance->fresh()))->toBe([]);

    // And it is still noticed when the image does change
    AppVersionImage::where('app_version_id', $instance->version_id)->first()->update(['tag' => '20270101_000000']);

    expect(driftedAspects($instance))->toBe(['release']);
});

it('does not stamp away what was already out of date', function () {
    $instance = detectionInstance();

    DB::table('app_version_images')->insert([
        'app_version_id' => $instance->version_id, 'key' => 'imaginary', 'role' => 'component', 'source' => 'override',
        'registry' => 'docker.io', 'repository' => 'nextcloud/aio-imaginary', 'tag' => '20261109_101109',
        'values_path' => 'imaginary.image', 'tag_from_version_name' => false, 'update_policy' => 'track', 'position' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Changed since it was applied, without the models noticing
    AppVersion::whereKey($instance->version_id)->update(['settings' => json_encode(['chart_version' => '9.9.9'])]);

    (require base_path('database/migrations/2026_11_17_000000_restamp_fingerprints_for_version_images.php'))->up();

    expect(app(UpdateDrift::class)->drift($instance->fresh()))->toBe(['release']);
});
