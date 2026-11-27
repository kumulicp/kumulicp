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
    $app_instance = Fixtures::instance('wordpress', settings: ['override' => ['pvc' => ['name' => 'restored-data']]]);
    FakeServerManager::$get_response = releaseWith([claim('found-on-the-cluster')]);

    expect((new JobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('restored-data');
});

it('asks the server for the claim when the instance names none, and keeps what it is told apart from a chosen one', function () {
    $app_instance = Fixtures::instance('wordpress');
    FakeServerManager::$get_response = releaseWith([['kind' => 'ConfigMap', 'name' => 'settings'], claim('wp-data')]);

    expect((new JobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('wp-data');

    $settings = $app_instance->fresh()->settings;
    // Not where a chosen claim is kept: nothing says a name there was chosen, and the release is given it
    expect($settings['workloads']['app']['volumes']['data'])->toBe(['discovered' => 'wp-data'])
        ->and($settings['override'] ?? [])->not->toHaveKey('pvc');
});

it('does not ask the server again for a claim it has found', function () {
    $app_instance = Fixtures::instance('wordpress');
    FakeServerManager::$get_response = releaseWith([claim('wp-data')]);
    (new JobChart($app_instance->organization, $app_instance))->persistentVolumeClaim();

    // The server would say something else now; the claim kept is the one mounted
    FakeServerManager::$get_response = releaseWith([claim('another')]);

    expect((new JobChart($app_instance->organization, $app_instance->fresh()))->persistentVolumeClaim())->toBe('wp-data');
});

it('mounts the claim that was chosen over the one that was found', function () {
    $app_instance = Fixtures::instance('wordpress', settings: ['workloads' => ['app' => ['volumes' => ['data' => ['claim' => 'chosen', 'discovered' => 'found']]]]]);

    expect((new JobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('chosen');
});

it('takes the first claim the release has, whichever volume it is for, as it is today', function () {
    // A release with a second workload's volume (a database, a cache) has two. Nothing says which is the app's
    $app_instance = Fixtures::instance('wordpress');
    FakeServerManager::$get_response = releaseWith([claim('data-mariadb-0'), claim('wp-data')]);

    expect((new JobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('data-mariadb-0')
        ->and($app_instance->fresh()->setting('workloads.app.volumes.data.discovered'))->toBe('data-mariadb-0');
});

it('still mounts the claim an app kept in override.pvc.name, whatever its chart', function () {
    // Where it was kept before volumes were kept in one place; a Nextcloud is not read there by the store
    $app_instance = Fixtures::instance('nextcloud', settings: ['override' => ['pvc' => ['name' => 'kept-before']]]);
    FakeServerManager::$get_response = releaseWith([claim('found-on-the-cluster')]);

    expect((new JobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('kept-before');
});

it('finds no claim, and writes nothing, when the server has none or does not answer', function (?array $answer) {
    $app_instance = Fixtures::instance('wordpress');
    FakeServerManager::$get_response = $answer;

    expect((new JobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBeNull()
        ->and($app_instance->fresh()->setting('workloads'))->toBeNull();
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
    expect(Fixtures::values($app_instance)['persistence']['existingClaim'])->toBe('');
});

describe('Nextcloud', function () {
    it('mounts the claim it was given, else the one its chart names after its release', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['existing_claim' => 'restored-data']);

        expect((new NextcloudJobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('restored-data');

        $named = Fixtures::instance('nextcloud', settings: ['override' => ['chart' => ['values' => ['fullNameOverride' => 'acme-nextcloud']]]]);

        expect((new NextcloudJobChart($named->organization, $named))->persistentVolumeClaim())->toBe('acme-nextcloud-nextcloud');
    });

    it('reads the claim it was given for an app whose profile is not Nextcloud\'s', function () {
        // The job is Nextcloud's, and it is the chart that says where the claim is kept, not the app
        $app_instance = Fixtures::instance('wordpress', settings: ['existing_claim' => 'restored-data']);

        expect((new NextcloudJobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('restored-data');
    });

    it('does not read override.pvc.name for a claim it was given, only for one found', function () {
        // existing_claim wins over everything, including a claim an earlier lookup kept
        $app_instance = Fixtures::instance('nextcloud', settings: ['existing_claim' => 'restored-data', 'override' => ['pvc' => ['name' => 'found-earlier']]]);

        expect((new NextcloudJobChart($app_instance->organization, $app_instance))->persistentVolumeClaim())->toBe('restored-data');
    });
});
