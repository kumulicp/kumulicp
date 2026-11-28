<?php

use App\AppInstance;
use App\Application;
use App\Integrations\Applications\CiviCRMStandalone\CiviCRMStandaloneProfile;
use App\Integrations\Applications\HelmForge\HelmForgeProfile;
use App\Integrations\Applications\Nextcloud\NextcloudProfile;
use App\Integrations\Applications\Wordpress\WordpressProfile;
use App\Support\StorageHandover;
use Illuminate\Support\Facades\Validator;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\TestSupports;

/**
 * Giving an app's chart a volume that already exists, under a name chosen on purpose: where it is written,
 * what is written, and putting back what was there.
 */
beforeEach(function () {
    // The directory is not what this is about, and not every machine that runs it has one
    setupAccountManagerDriver('db');

    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();

    $this->appInstance = AppInstance::first();
});

afterEach(function () {
    DemoAppProfile::$storage_handover = null;
});

// What every app is given, as the demo app's chart
function dataVolumeMap(): array
{
    return (new NextcloudProfile)->storageHandover(new AppInstance);
}

// A catalog chart that was detected to keep a volume
function catalogProfile(): HelmForgeProfile
{
    $application = new Application;
    $application->catalog = ['convention' => ['persistence' => ['size' => 'persistence.size']]];

    return new HelmForgeProfile($application);
}

it('is the same place for every app, since they all keep a volume at volumes.<name>', function () {
    $map = [
        'claim' => ['path' => 'volumes.data.claim'],
        'access_mode' => ['path' => 'volumes.data.access_mode'],
        'storage_class' => ['path' => 'volumes.data.storage_class'],
        'size' => ['path' => 'volumes.data.size'],
    ];

    expect((new NextcloudProfile)->storageHandover(new AppInstance))->toBe($map)
        ->and((new WordpressProfile)->storageHandover(new AppInstance))->toBe($map)
        ->and((new CiviCRMStandaloneProfile)->storageHandover(new AppInstance))->toBe($map);
});

it('never gives a catalog chart an access mode, and has no way to give a claim to a chart with no volume', function () {
    expect(catalogProfile()->storageHandover(new AppInstance))->not->toHaveKey('access_mode')
        ->toHaveKeys(['claim', 'storage_class', 'size'])
        ->and((new HelmForgeProfile(new Application))->storageHandover(new AppInstance))->toBeNull();
});

it('has no way to give a claim to a profile that does not say', function () {
    DemoAppProfile::$storage_handover = null;

    expect(StorageHandover::supports($this->appInstance))->toBeFalse()
        ->and(StorageHandover::fields($this->appInstance))->toBe([]);

    StorageHandover::apply($this->appInstance, ['volume' => 'new']);
})->throws(LogicException::class);

it('lists the fields the chart can be given', function () {
    DemoAppProfile::$storage_handover = catalogProfile()->storageHandover(new AppInstance);

    expect(StorageHandover::supports($this->appInstance))->toBeTrue()
        ->and(StorageHandover::fields($this->appInstance))->toBe(['claim', 'storage_class', 'size']);
});

it('needs a claim for an existing volume, and a decision between existing and new', function (array $data, bool $valid) {
    expect(Validator::make($data, StorageHandover::rules())->passes())->toBe($valid);
})->with([
    'existing with a claim' => [['volume' => 'existing', 'claim' => 'restored-data'], true],
    'existing without one' => [['volume' => 'existing'], false],
    'new without a claim' => [['volume' => 'new'], true],
    'nothing decided' => [[], false],
    'a claim that is not a name' => [['volume' => 'existing', 'claim' => 'Restored Data'], false],
    'an access mode' => [['volume' => 'new', 'access_mode' => 'ReadWriteMany'], true],
    'an access mode that does not exist' => [['volume' => 'new', 'access_mode' => 'Everywhere'], false],
    'a size' => [['volume' => 'new', 'size' => 20], true],
    'a size of nothing' => [['volume' => 'new', 'size' => 0], false],
]);

it('writes the claim and the volume settings where the chart reads them', function () {
    DemoAppProfile::$storage_handover = dataVolumeMap();
    $this->appInstance->settings = ['volumes' => ['data' => ['claim' => 'old-claim', 'storage_class' => 'old-class']]];
    $this->appInstance->save();

    $before = StorageHandover::apply($this->appInstance, [
        'volume' => 'existing',
        'claim' => 'restored-data',
        'access_mode' => 'ReadWriteMany',
        'storage_class' => 'longhorn-restored',
        'size' => '40',
    ]);

    expect($this->appInstance->fresh()->settings['volumes']['data'])->toBe([
        'claim' => 'restored-data',
        'storage_class' => 'longhorn-restored',
        'access_mode' => 'ReadWriteMany',
        'size' => 40,
    ]);

    // What was there is kept, and what was not is known not to have been
    expect($before['volumes.data.claim'])->toBe(['value' => 'old-claim'])
        ->and($before['volumes.data.storage_class'])->toBe(['value' => 'old-class'])
        ->and($before['volumes.data.access_mode'])->toBeNull()
        ->and($before['volumes.data.size'])->toBeNull();
});

it('clears a claim kept from the old volume when the app is to have a new one', function () {
    DemoAppProfile::$storage_handover = dataVolumeMap();
    $this->appInstance->settings = ['volumes' => ['data' => ['claim' => 'old-claim']]];
    $this->appInstance->save();

    StorageHandover::apply($this->appInstance, ['volume' => 'new']);

    expect($this->appInstance->fresh()->settings['volumes']['data']['claim'])->toBe('');
});

it('leaves what is not given alone', function () {
    DemoAppProfile::$storage_handover = dataVolumeMap();
    $this->appInstance->settings = ['volumes' => ['data' => ['access_mode' => 'ReadWriteOnce']]];
    $this->appInstance->save();

    StorageHandover::apply($this->appInstance, ['volume' => 'existing', 'claim' => 'restored-data']);

    expect($this->appInstance->fresh()->settings['volumes']['data'])->toBe(['access_mode' => 'ReadWriteOnce', 'claim' => 'restored-data']);
});

it('puts back exactly what was there', function () {
    DemoAppProfile::$storage_handover = dataVolumeMap();
    $original = ['volumes' => ['data' => ['claim' => 'old-claim', 'access_mode' => 'ReadWriteOnce']], 'other' => 'kept'];
    $this->appInstance->settings = $original;
    $this->appInstance->save();

    $before = StorageHandover::apply($this->appInstance, ['volume' => 'existing', 'claim' => 'restored-data', 'access_mode' => 'ReadWriteMany', 'size' => 50]);
    // It goes through the task's saved values on the way
    $before = json_decode(json_encode($before), true);

    StorageHandover::restore($this->appInstance->fresh(), $before);

    expect($this->appInstance->fresh()->settings)->toBe($original);
});
