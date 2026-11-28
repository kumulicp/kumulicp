<?php

use App\Integrations\ServerManagers\Kubernetes\Charts\Job\JobChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\Job\NextcloudJobChart;
use Tests\Support\Applications\VolumeFixtures as Fixtures;
use Tests\Support\ServerManagers\FakeServerManager;

/**
 * Which claim a job mounts, and what looking for it leaves in the instance's settings.
 *
 * Pins the CURRENT behaviour before the volumes are moved to one settings location
 * (docs/volumes-and-settings-plan.md, Slice 0), quirks included. Of these, the one that matters most is
 * that the claim the cluster reports is kept in the same setting as a claim somebody chose.
 */
beforeEach(function () {
    setupAccountManagerDriver('db');
    setupFakeServerInterfaces();
});

afterEach(function () {
    FakeServerManager::reset();
});

// What the server says about a release: its resources, among them the claims
function releaseWith(array $resources): array
{
    return ['status' => 'success', 'response' => ['spec' => ['resources' => $resources]]];
}

function claim(string $name): array
{
    return ['kind' => 'PersistentVolumeClaim', 'name' => $name];
}

it('mounts the claim the instance names, without asking the server', function () {
    $app_instance = Fixtures::instance('wordpress', settings: ['volumes' => ['data' => ['claim' => 'restored-data']]]);
    FakeServerManager::$get_response = releaseWith([claim('found-on-the-cluster')]);

    expect((new JobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('restored-data');
});

it('mounts the claim the instance had before the migration, once it is migrated', function () {
    $app_instance = Fixtures::migrated(Fixtures::instance('wordpress', settings: ['override' => ['pvc' => ['name' => 'restored-data']]]));
    FakeServerManager::$get_response = releaseWith([claim('found-on-the-cluster')]);

    expect((new JobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('restored-data');
});

it('asks the server for the claim when the instance names none, and keeps nothing', function () {
    $app_instance = Fixtures::instance('wordpress');
    $settings = $app_instance->settings;
    FakeServerManager::$get_response = releaseWith([['kind' => 'ConfigMap', 'name' => 'settings'], claim('wp-data')]);

    expect((new JobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('wp-data')
        // What the cluster says is not kept: nothing says it was chosen, and the release would be given it
        ->and($app_instance->fresh()->settings)->toBe($settings);
});

it('takes the first claim the release has, whichever volume it is for, as it is today', function () {
    // A release with a second workload's volume (a database, a cache) has two. Nothing says which is the app's
    $app_instance = Fixtures::instance('wordpress');
    FakeServerManager::$get_response = releaseWith([claim('data-mariadb-0'), claim('wp-data')]);

    expect((new JobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('data-mariadb-0');
});

it('finds no claim, and writes nothing, when the server has none or does not answer', function (?array $answer) {
    $app_instance = Fixtures::instance('wordpress');
    $settings = $app_instance->settings;
    FakeServerManager::$get_response = $answer;

    expect((new JobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBeNull()
        ->and($app_instance->fresh()->settings)->toBe($settings);
})->with([
    'no answer' => [null],
    'a failure' => [['status' => 'failed', 'response' => 'unreachable']],
    'a release with no claim' => [releaseWith([['kind' => 'ConfigMap', 'name' => 'settings']])],
]);

it('does not give the claim a job found to the next release as a claim that exists', function () {
    $app_instance = Fixtures::instance('wordpress');
    FakeServerManager::$get_response = releaseWith([claim('wp-data')]);

    (new JobChart($app_instance->organization, $app_instance))->persistentVolumeClaim();

    // The chart's own claim, found on the cluster, is not what the chart is told to use: it makes it itself
    expect(Fixtures::values($app_instance->fresh())['persistence']['existingClaim'])->toBe('');
});

it('finds the claim of an app that has no volume of that name by asking the server', function () {
    // The generic app's volumes are a list the plan defines
    $app_instance = Fixtures::instance('generic', ['persistence-enabled' => true]);
    FakeServerManager::$get_response = releaseWith([claim('generic-data')]);

    expect((new JobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('generic-data');
});

describe('Nextcloud', function () {
    it('mounts the claim it was given, else the one its chart names after its release', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['volumes' => ['data' => ['claim' => 'restored-data']]]);

        expect((new NextcloudJobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('restored-data');

        $named = Fixtures::instance('nextcloud', settings: ['override' => ['chart' => ['values' => ['fullNameOverride' => 'acme-nextcloud']]]]);

        expect((new NextcloudJobChart($named->organization, $named))->persistentVolumeClaim())->toBe('acme-nextcloud-nextcloud');
    });

    it('mounts the claim it was given before the migration, once it is migrated', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['existing_claim' => 'restored-data']);
        $app_instance = Fixtures::migrated($app_instance);

        expect((new NextcloudJobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('restored-data');
    });

    it('mounts the claim it was given before anything a job found', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['volumes' => ['data' => ['claim' => 'restored-data']], 'override' => ['pvc' => ['name' => 'found-earlier']]]);

        expect((new NextcloudJobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('restored-data');
    });
});
