<?php

use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\AppVersionImage;
use App\Enums\VersionTestRunStatus;
use App\Support\VersionTesting\VersionFingerprint;
use App\VersionTestRun;
use Illuminate\Support\Facades\DB;

/**
 * A version's images are part of what a version test exercises: changing one
 * means the version has to be tested again. A version that only has the image
 * its settings describe keeps the tests it has passed.
 */
beforeEach(function () {
    $this->application = Application::factory()->create();
    $this->version = AppVersion::factory()->create([
        'application_id' => $this->application->id,
        'name' => '33.0.1',
        'status' => 'deactivated',
        'settings' => ['image_registry' => 'docker.io', 'image_repo_name' => 'library/nextcloud'],
    ]);
    $this->plan = AppPlan::factory()->create(['application_id' => $this->application->id]);
});

function approvedRun(AppVersion $version, ?AppPlan $plan = null, bool $with_images = true): VersionTestRun
{
    $fingerprints = new VersionFingerprint;

    $run = new VersionTestRun;
    $run->application_id = $version->application_id;
    $run->app_version_id = $version->id;
    $run->app_plan_id = $plan?->id;
    $run->status = VersionTestRunStatus::PASSED;
    $run->fingerprint = $plan ? $fingerprints->forPlan($version, $plan, $with_images) : $fingerprints->for($version, $with_images);
    $run->finished_at = now();
    $run->save();

    return $run;
}

function addImage(AppVersion $version, array $attributes = []): AppVersionImage
{
    return AppVersionImage::factory()->create($attributes + ['app_version_id' => $version->id, 'key' => 'imaginary', 'tag' => '20261109_101109', 'values_path' => 'imaginary.image']);
}

it('keeps the fingerprint of a version that only has the image its settings describe', function () {
    $fingerprints = new VersionFingerprint;

    expect($this->version->images)->toHaveCount(1)
        ->and($fingerprints->for($this->version))->toBe($fingerprints->for($this->version, false))
        ->and($fingerprints->forPlan($this->version, $this->plan))->toBe($fingerprints->forPlan($this->version, $this->plan, false));
});

it('keeps a passed test passed when the version gains no image of its own', function () {
    approvedRun($this->version);

    // The primary image follows the settings, which are part of the fingerprint already
    expect($this->version->fresh()->testGate())->toBe('passed');
});

it('makes the tests stale when the version gains an image', function () {
    approvedRun($this->version);

    addImage($this->version);

    expect($this->version->fresh()->testGate())->toBe('stale')
        ->and((new VersionFingerprint)->for($this->version))->not->toBe((new VersionFingerprint)->for($this->version, false));
});

it('makes the tests stale for a change to what an image runs', function (array $change) {
    $image = addImage($this->version);
    approvedRun($this->version);
    expect($this->version->fresh()->testGate())->toBe('passed');

    $image->update($change);

    expect($this->version->fresh()->testGate())->toBe('stale');
})->with([
    'tag' => [['tag' => '20270101_000000']],
    'digest' => [['digest' => 'sha256:abc123']],
    'repository' => [['repository' => 'other/imaginary']],
    'registry' => [['registry' => 'ghcr.io']],
    'values path' => [['values_path' => 'other.image']],
    'role' => [['role' => 'job']],
    'source' => [['source' => 'chart']],
]);

it('makes the tests stale when an image is removed or its registry login changes', function () {
    $image = addImage($this->version);
    $secret = App\RepoSecret::factory()->create();
    approvedRun($this->version);

    $image->update(['registry_secret_id' => $secret->id]);
    expect($this->version->fresh()->testGate())->toBe('stale');

    $run = approvedRun($this->version->fresh());
    expect($this->version->fresh()->testGate())->toBe('passed');

    $image->delete();
    expect($run->fresh()->fingerprint)->not->toBe((new VersionFingerprint)->for($this->version->fresh()))
        ->and($this->version->fresh()->testGate())->toBe('stale');
});

it('keeps the tests passed for what is only about showing an image or checking it for updates', function (array $change) {
    $image = addImage($this->version);
    approvedRun($this->version);

    $image->update($change);

    expect($this->version->fresh()->testGate())->toBe('passed');
})->with([
    'label' => [['label' => 'Another name']],
    'position' => [['position' => 5]],
    'update policy' => [['update_policy' => 'pinned']],
    'tag pattern' => [['tag_pattern' => '^\d+$']],
    'group' => [['group' => 'synapse']],
]);

it('stales the tests of the plan the version was tested on when an image changes', function () {
    $image = addImage($this->version);
    approvedRun($this->version, $this->plan);

    expect($this->version->fresh()->testGate($this->plan))->toBe('passed');

    $image->update(['tag' => '20270101_000000']);

    expect($this->version->fresh()->testGate($this->plan))->toBe('stale');
});

it('does not mix up the images of one version with another\'s', function () {
    $other = AppVersion::factory()->create(['application_id' => $this->application->id, 'name' => '33.0.2', 'status' => 'deactivated']);
    approvedRun($this->version);

    addImage($other);

    expect($this->version->fresh()->testGate())->toBe('passed');
});

// ─── Stamping the tests that were current ────────────────────────────────────

function restampMigration(): object
{
    return require base_path('database/migrations/2026_11_17_000000_restamp_fingerprints_for_version_images.php');
}

// A version that has had its image since before images were part of the fingerprint
function insertedImage(AppVersion $version): void
{
    DB::table('app_version_images')->insert([
        'app_version_id' => $version->id,
        'key' => 'imaginary',
        'role' => 'component',
        'source' => 'override',
        'registry' => 'docker.io',
        'repository' => 'nextcloud/aio-imaginary',
        'tag' => '20261109_101109',
        'values_path' => 'imaginary.image',
        'tag_from_version_name' => false,
        'update_policy' => 'track',
        'position' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('stamps the passed tests of a version that already had images, so they still count', function () {
    insertedImage($this->version);
    // Passed under the old fingerprint
    $run = approvedRun($this->version, null, false);
    $plan_run = approvedRun($this->version, $this->plan, false);

    expect($this->version->fresh()->testGate())->toBe('stale')
        ->and($this->version->fresh()->testGate($this->plan))->toBe('stale');

    restampMigration()->up();

    expect($this->version->fresh()->testGate())->toBe('passed')
        ->and($this->version->fresh()->testGate($this->plan))->toBe('passed')
        ->and($run->fresh()->fingerprint)->toBe((new VersionFingerprint)->for($this->version->fresh()))
        ->and($plan_run->fresh()->fingerprint)->toBe((new VersionFingerprint)->forPlan($this->version->fresh(), $this->plan));
});

it('leaves a test that was already stale stale', function () {
    insertedImage($this->version);
    $run = approvedRun($this->version, null, false);

    // The version changed after it was tested
    $this->version->updateSettings(['chart_version' => '9.9.9']);
    $before = $run->fresh()->fingerprint;

    restampMigration()->up();

    expect($run->fresh()->fingerprint)->toBe($before)
        ->and($this->version->fresh()->testGate())->toBe('stale');
});

it('leaves the tests of versions without images of their own as they are', function () {
    $run = approvedRun($this->version);
    $fingerprint = $run->fingerprint;

    restampMigration()->up();

    expect($run->fresh()->fingerprint)->toBe($fingerprint)
        ->and($this->version->fresh()->testGate())->toBe('passed');
});
