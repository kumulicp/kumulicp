<?php

use App\Organization;
use App\Support\Volumes\Volumes;
use App\User;
use Tests\Support\Applications\VolumeFixtures as Fixtures;
use Tests\Support\TestSupports;

/**
 * The admin's form for an instance's volumes (docs/volumes-and-settings-plan.md, the forms). A volume's settings
 * are given when it is enabled, and are fixed once it is in use, as Kubernetes fixes them. The only change that
 * is left is the claim of the volume that holds the files.
 */
beforeEach(function () {
    setupAccountManagerDriver('db');
    (new TestSupports)->seed();
    $this->actingAs(User::find(1));
});

function volumeUrl($app_instance, string $volume, string $suffix = ''): string
{
    return "/admin/organizations/{$app_instance->organization_id}/apps/{$app_instance->id}/volumes/{$volume}{$suffix}";
}

it('shows what is known of each volume, which can be set and which can not', function () {
    $app_instance = Fixtures::instance('nextcloud', settings: ['volumes' => ['data' => ['enabled' => true, 'storage_class' => 'fast', 'claim' => 'restored-data']]]);
    $app_instance->forceFill(['status' => 'active'])->save();

    $this->get("/admin/organizations/{$app_instance->organization_id}/apps/{$app_instance->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->where('volumes.0.name', 'data')
            ->where('volumes.0.locked', true)
            ->where('volumes.0.can_change_claim', true)
            ->where('volumes.0.effective.storage_class', 'fast')
            ->where('volumes.0.effective.claim', 'restored-data')
            ->where('volumes.1.name', 'redis-master')
            ->where('volumes.1.locked', false)
            ->where('volumes.1.can_change_claim', false));
});

it('enables the data volume with its class, which can not be set after', function () {
    $app_instance = Fixtures::instance('nextcloud');

    $this->put(volumeUrl($app_instance, 'data'), ['enabled' => true, 'storage_class' => 'fast'])->assertSessionHasNoErrors();
    $this->put(volumeUrl($app_instance, 'data'), ['enabled' => true, 'storage_class' => 'slow'])->assertSessionHas('error');

    expect(Volumes::for($app_instance->fresh())->get('data')->storageClass())->toBe('fast');
});

it('gives a volume its settings while it is enabled for the first time', function () {
    $app_instance = Fixtures::instance('nextcloud');

    $this->put(volumeUrl($app_instance, 'redis-master'), ['enabled' => true, 'size' => 4, 'storage_class' => 'fast'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $volume = Volumes::for($app_instance->fresh())->get('redis-master');

    expect($volume->enabled())->toBeTrue()->and($volume->size())->toBe(4)->and($volume->storageClass())->toBe('fast');
});

it('does not change the settings of a volume that is in use', function (string $name, array $values) {
    $app_instance = Fixtures::instance('nextcloud', settings: ['volumes' => ['data' => ['enabled' => true], 'redis-master' => ['enabled' => true, 'size' => 4]]]);
    $before = $app_instance->fresh()->settings;

    $this->put(volumeUrl($app_instance, $name), $values)->assertSessionHas('error');

    expect($app_instance->fresh()->settings)->toBe($before);
})->with([
    'one enabled before' => ['redis-master', ['enabled' => true, 'size' => 9]],
    'the data volume, always in use' => ['data', ['storage_class' => 'fast']],
]);

it('refuses what a volume that is not in use can not have', function (array $values, string $key) {
    $app_instance = Fixtures::instance('nextcloud');

    $this->put(volumeUrl($app_instance, 'redis-master'), ['enabled' => true, ...$values])->assertSessionHasErrors($key);
})->with([
    'a class that is not a name' => [['storage_class' => 'Not A Name'], 'storage_class'],
    'an access mode that does not exist' => [['access_mode' => 'Sometimes'], 'access_mode'],
    'not enabling it' => [['enabled' => false], 'enabled'],
    'a size of nothing' => [['size' => 0], 'size'],
]);

it('is not found for a volume the app does not have, or another organization', function () {
    $app_instance = Fixtures::instance('nextcloud');
    $other = Organization::factory()->create();

    $this->put(volumeUrl($app_instance, 'nope'), ['enabled' => true])->assertNotFound();
    $this->put("/admin/organizations/{$other->id}/apps/{$app_instance->id}/volumes/redis-master", ['enabled' => true])->assertNotFound();
});

describe('changing the claim', function () {
    it('points the volume that holds the files at another existing claim', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['volumes' => ['data' => ['enabled' => true, 'claim' => 'old-data']]]);
        $app_instance->forceFill(['status' => 'active'])->save();

        $this->put(volumeUrl($app_instance, 'data', '/claim'), ['claim' => 'restored-data'])->assertRedirect()->assertSessionHasNoErrors();

        expect(Volumes::for($app_instance->fresh())->get('data')->claim())->toBe('restored-data');
    });

    it('is refused while the app is being worked on', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['volumes' => ['data' => ['enabled' => true]]]);
        $app_instance->forceFill(['status' => 'updating'])->save();

        $this->put(volumeUrl($app_instance, 'data', '/claim'), ['claim' => 'restored-data'])->assertSessionHas('error');

        expect(Volumes::for($app_instance->fresh())->get('data')->claim())->toBeNull();
    });

    it('is refused for a volume that makes its own claim', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['volumes' => ['redis-master' => ['enabled' => true]]]);
        $app_instance->forceFill(['status' => 'active'])->save();

        $this->put(volumeUrl($app_instance, 'redis-master', '/claim'), ['claim' => 'mine'])->assertSessionHas('error');
    });

    it('wants a name that is a claim name', function (?string $claim) {
        $app_instance = Fixtures::instance('nextcloud', settings: ['volumes' => ['data' => ['enabled' => true]]]);
        $app_instance->forceFill(['status' => 'active'])->save();

        $this->put(volumeUrl($app_instance, 'data', '/claim'), ['claim' => $claim])->assertSessionHasErrors('claim');
    })->with([[null], ['Not A Name']]);
});
