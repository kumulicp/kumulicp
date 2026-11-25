<?php

use App\AppInstance;
use App\Integrations\Applications\CiviCRMStandalone\CiviCRMStandaloneProfile;
use App\Integrations\Applications\HelmForge\HelmForgeProfile;
use App\Integrations\Applications\Nextcloud\NextcloudProfile;
use App\Integrations\Applications\Wordpress\WordpressProfile;
use App\Support\StorageHandover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * Giving an app's chart a volume that already exists, under a name chosen on purpose: where each
 * chart reads it from, what is written, and putting back what was there.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    (new TestSupports)->seed();

    $this->appInstance = AppInstance::first();
});

afterEach(function () {
    DemoAppProfile::$storage_handover = null;
});

// Where Nextcloud's chart looks, and what it was given before, as the demo app's chart
function nextcloudMap(): array
{
    return (new NextcloudProfile)->storageHandover(new AppInstance);
}

it('says where each chart reads the claim from', function () {
    expect((new NextcloudProfile)->storageHandover(new AppInstance)['claim'])->toBe(['path' => 'existing_claim'])
        ->and((new WordpressProfile)->storageHandover(new AppInstance)['claim'])->toBe(['path' => 'override.pvc.name'])
        ->and((new WordpressProfile)->storageHandover(new AppInstance)['access_mode'])->toBe(['path' => 'configurations.persistence-accessModes', 'as' => 'list'])
        ->and((new CiviCRMStandaloneProfile)->storageHandover(new AppInstance)['claim']['with'])->toBe(['override.pvc.override' => true])
        ->and((new HelmForgeProfile(new App\Application))->storageHandover(new AppInstance))->not->toHaveKey('access_mode');
});

it('has no way to give a claim to a profile that does not say', function () {
    expect(StorageHandover::supports($this->appInstance))->toBeFalse()
        ->and(StorageHandover::fields($this->appInstance))->toBe([]);

    StorageHandover::apply($this->appInstance, ['volume' => 'new']);
})->throws(LogicException::class);

it('lists the fields the chart can be given', function () {
    DemoAppProfile::$storage_handover = (new HelmForgeProfile(new App\Application))->storageHandover(new AppInstance);

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
    DemoAppProfile::$storage_handover = (new CiviCRMStandaloneProfile)->storageHandover(new AppInstance);
    $this->appInstance->settings = ['override' => ['pvc' => ['name' => 'old-claim']], 'configurations' => ['persistence-storageClass' => 'old-class']];
    $this->appInstance->save();

    $before = StorageHandover::apply($this->appInstance, [
        'volume' => 'existing',
        'claim' => 'restored-data',
        'access_mode' => 'ReadWriteMany',
        'storage_class' => 'longhorn-restored',
        'size' => '40',
    ]);

    $settings = $this->appInstance->fresh()->settings;
    expect($settings['override']['pvc'])->toBe(['name' => 'restored-data', 'override' => true])
        ->and($settings['configurations'])->toBe(['persistence-storageClass' => 'longhorn-restored', 'persistence-accessModes' => 'ReadWriteMany'])
        ->and($settings['chart_storage'])->toBe(40);

    // What was there is kept, and what was not is known not to have been
    expect($before['override.pvc.name'])->toBe(['value' => 'old-claim'])
        ->and($before['configurations.persistence-storageClass'])->toBe(['value' => 'old-class'])
        ->and($before['configurations.persistence-accessModes'])->toBeNull()
        ->and($before['chart_storage'])->toBeNull();
});

it('lists an access mode for a chart that takes a list', function () {
    DemoAppProfile::$storage_handover = (new WordpressProfile)->storageHandover(new AppInstance);

    StorageHandover::apply($this->appInstance, ['volume' => 'existing', 'claim' => 'restored-data', 'access_mode' => 'ReadWriteMany']);

    expect($this->appInstance->fresh()->settings['configurations']['persistence-accessModes'])->toBe(['ReadWriteMany']);
});

it('clears a claim kept from the old volume when the app is to have a new one', function () {
    DemoAppProfile::$storage_handover = (new CiviCRMStandaloneProfile)->storageHandover(new AppInstance);
    $this->appInstance->settings = ['override' => ['pvc' => ['name' => 'old-claim', 'override' => true]]];
    $this->appInstance->save();

    StorageHandover::apply($this->appInstance, ['volume' => 'new']);

    expect($this->appInstance->fresh()->settings['override']['pvc'])->toBe(['name' => '', 'override' => false]);
});

it('leaves what is not given alone', function () {
    DemoAppProfile::$storage_handover = nextcloudMap();
    $this->appInstance->settings = ['configurations' => ['persistence-accessMode' => 'ReadWriteOnce']];
    $this->appInstance->save();

    StorageHandover::apply($this->appInstance, ['volume' => 'existing', 'claim' => 'restored-data']);

    $settings = $this->appInstance->fresh()->settings;
    expect($settings['existing_claim'])->toBe('restored-data')
        ->and($settings['configurations'])->toBe(['persistence-accessMode' => 'ReadWriteOnce'])
        ->and($settings)->not->toHaveKey('chart_storage');
});

it('puts back exactly what was there', function () {
    DemoAppProfile::$storage_handover = nextcloudMap();
    $original = ['existing_claim' => 'old-claim', 'configurations' => ['persistence-accessMode' => 'ReadWriteOnce'], 'other' => 'kept'];
    $this->appInstance->settings = $original;
    $this->appInstance->save();

    $before = StorageHandover::apply($this->appInstance, ['volume' => 'existing', 'claim' => 'restored-data', 'access_mode' => 'ReadWriteMany', 'size' => 50]);
    // It goes through the task's saved values on the way
    $before = json_decode(json_encode($before), true);

    StorageHandover::restore($this->appInstance->fresh(), $before);

    $restored = $this->appInstance->fresh()->settings;
    expect($restored['existing_claim'])->toBe('old-claim')
        ->and($restored['configurations'])->toBe(['persistence-accessMode' => 'ReadWriteOnce'])
        ->and($restored['other'])->toBe('kept')
        ->and($restored)->not->toHaveKey('chart_storage');
});
