<?php

use App\Integrations\Applications\HelmForge\HelmForgeChart;
use App\Integrations\Applications\HelmForge\HelmForgeProfile;
use App\Support\Facades\Application;
use App\Support\StorageHandover;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\Applications\VolumeFixtures as Fixtures;
use Tests\Support\TestSupports;

/**
 * An app with two charts has two layouts for its volume, one for each chart, so what an instance is
 * given for its volume has to come from the chart it runs, not from its app (docs/volumes-and-settings-plan.md,
 * "Several charts for one app"). Pins that, before the volume code is moved.
 */
beforeEach(function () {
    setupAccountManagerDriver('db');
    (new TestSupports)->seed();
    [$this->own, $this->added] = Fixtures::onTwoCharts(
        ['persistence' => ['enabled' => true, 'storageClass' => '', 'size' => '10Gi', 'existingClaim' => ''], 'replicaCount' => 1, 'fullnameOverride' => ''],
        ['override' => ['pvc' => ['name' => 'restored-data']]],
    );
});

afterEach(function () {
    DemoAppProfile::$storage_handover = null;
});

it('deploys each instance with the chart it runs, though they are the same app', function () {
    expect($this->own->application_id)->toBe($this->added->application_id)
        ->and(Application::profileFor($this->own))->toBeInstanceOf(DemoAppProfile::class)
        ->and(Application::profileFor($this->added))->toBeInstanceOf(HelmForgeProfile::class)
        ->and(Fixtures::chart($this->added))->toBeInstanceOf(HelmForgeChart::class)
        ->and(Fixtures::chart($this->own))->not->toBeInstanceOf(HelmForgeChart::class);
});

it('reads the volume layout of the instance from the chart it runs, not from its app', function () {
    // The app's own profile has no way to be given a claim; the added chart's has
    expect(StorageHandover::supports($this->own))->toBeFalse()
        ->and(StorageHandover::supports($this->added))->toBeTrue()
        ->and(StorageHandover::map($this->added)['claim'])->toBe(['path' => 'override.pvc.name']);

    // Whichever the app is registered with does not change what an instance on the added chart is given
    DemoAppProfile::$storage_handover = ['claim' => ['path' => 'existing_claim']];

    expect(StorageHandover::map($this->own)['claim'])->toBe(['path' => 'existing_claim'])
        ->and(StorageHandover::map($this->added)['claim'])->toBe(['path' => 'override.pvc.name']);
});

it('gives the instance on the added chart its claim at the place that chart was detected to take it', function () {
    $values = Fixtures::values($this->added);

    // The application has no catalog data of its own: this is the chart's
    expect(Fixtures::only($values, ['persistence.existingClaim', 'persistence.size']))->toBe([
        'persistence.existingClaim' => 'restored-data',
        'persistence.size' => '2Gi',
    ]);
});
