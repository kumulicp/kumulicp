<?php

use App\Organization;
use App\Support\Volumes\Volumes;
use App\User;
use Tests\Support\Applications\VolumeFixtures as Fixtures;
use Tests\Support\TestSupports;

/**
 * The admin's form for an instance's volumes (docs/volumes-and-settings-plan.md, the forms): it offers each
 * volume what that volume can have, and writes the instance's `volumes.<name>`.
 */
beforeEach(function () {
    setupAccountManagerDriver('db');
    (new TestSupports)->seed();
    $this->actingAs(User::find(1));
});

function volumesUrl($app_instance): string
{
    return "/admin/organizations/{$app_instance->organization_id}/apps/{$app_instance->id}/volumes";
}

it('shows each volume the app has, with what is its own and what is in effect', function () {
    $app_instance = Fixtures::instance('nextcloud', settings: ['volumes' => ['data' => ['storage_class' => 'fast']]]);

    $this->get("/admin/organizations/{$app_instance->organization_id}/apps/{$app_instance->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->where('volumes.0.name', 'data')
            ->where('volumes.0.takes_claim', true)
            ->where('volumes.0.follows_quota', true)
            ->where('volumes.0.own.storage_class', 'fast')
            ->where('volumes.1.name', 'redis-master')
            ->where('volumes.1.takes_claim', false));
});

it('writes what is sent into the volume, and forgets what is blank', function () {
    $app_instance = Fixtures::instance('nextcloud', settings: ['volumes' => ['redis-master' => ['size' => 3]]]);

    $this->put(volumesUrl($app_instance), ['volumes' => [
        'data' => ['storage_class' => 'fast', 'access_mode' => 'ReadWriteMany', 'claim' => 'restored-data'],
        'redis-master' => ['enabled' => true, 'size' => null],
    ]])->assertRedirect()->assertSessionHasNoErrors();

    $volumes = Volumes::for($app_instance->fresh());

    expect($volumes->get('data')->storageClass())->toBe('fast')
        ->and($volumes->get('data')->accessMode())->toBe('ReadWriteMany')
        ->and($volumes->get('data')->claim())->toBe('restored-data')
        ->and($volumes->get('redis-master')->enabled())->toBeTrue()
        // Blank, so the profile's size applies again
        ->and($volumes->get('redis-master')->size())->toBe(8);
});

it('refuses what the volume can not have', function (array $volumes, string $key) {
    $app_instance = Fixtures::instance('nextcloud');

    $this->put(volumesUrl($app_instance), ['volumes' => $volumes])->assertSessionHasErrors($key);

    expect($app_instance->fresh()->settings['volumes'] ?? [])->toBe([]);
})->with([
    'a claim on a volume that makes its own' => [['redis-master' => ['claim' => 'mine']], 'volumes.redis-master.claim'],
    'a size on the volume that follows the quota' => [['data' => ['size' => 50]], 'volumes.data.size'],
    'a switch on a volume the app can not do without' => [['data' => ['enabled' => false]], 'volumes.data.enabled'],
    'a volume the app does not have' => [['nope' => ['size' => 1]], 'volumes'],
    'a class that is not a name' => [['data' => ['storage_class' => 'Not A Name']], 'volumes.data.storage_class'],
    'an access mode that does not exist' => [['data' => ['access_mode' => 'Sometimes']], 'volumes.data.access_mode'],
]);

it('is refused for another organization\'s app', function () {
    $app_instance = Fixtures::instance('nextcloud');
    $other = Organization::factory()->create();

    $this->put("/admin/organizations/{$other->id}/apps/{$app_instance->id}/volumes", ['volumes' => ['data' => ['storage_class' => 'fast']]])
        ->assertNotFound();
});
