<?php

use Tests\Support\Applications\DemoHelmChart;
use Tests\Support\Applications\VolumeFixtures as Fixtures;

/**
 * What appStorage(), which charts that write their own persistence still call, sizes a chart by.
 */
beforeEach(function () {
    // The directory is not what these are about, and not every machine that runs them has one
    setupAccountManagerDriver('db');
});

it('is the size of the volume that holds the files, which follows the quota and only grows, kept in the volume', function () {
    $app_instance = Fixtures::volumesInstance(settings: ['storage_quota' => 10]);
    $chart = new DemoHelmChart($app_instance->organization, $app_instance);

    expect($chart->appStorage())->toBe(10)
        ->and($app_instance->fresh()->setting('volumes.data.size'))->toBe(10)
        // Not where it was kept before
        ->and($app_instance->fresh()->setting('chart_storage'))->toBeNull();

    $app_instance->updateSetting('storage_quota', 4);

    expect($chart->appStorage())->toBe(10);
});

it('is the storage of the release the chart is given, and keeps nothing', function () {
    $app_instance = Fixtures::volumesInstance(settings: ['storage_quota' => 10]);
    $chart = new DemoHelmChart($app_instance->organization, $app_instance);
    $chart->app_storage = 7;

    expect($chart->appStorage())->toBe(7)
        ->and($app_instance->fresh()->setting('volumes'))->toBeNull();
});

it('is the smallest size, not 0, when nothing is worked out yet', function () {
    $app_instance = Fixtures::volumesInstance();
    $chart = new DemoHelmChart($app_instance->organization, $app_instance);
    $chart->app_storage = 0;

    // Not given any: the instance's own, which its plan gives
    expect($chart->appStorage())->toBeGreaterThanOrEqual(1);
});

it('is still kept in chart_storage for the generic app, whose volumes are a list the plan defines', function () {
    $app_instance = Fixtures::instance('generic', ['persistence-enabled' => true], ['storage_quota' => 10]);
    $chart = Fixtures::chart($app_instance);

    expect($chart->appStorage())->toBe(10)
        ->and($app_instance->fresh()->setting('chart_storage'))->toBe(10)
        ->and($app_instance->fresh()->setting('volumes'))->toBeNull();
});
