<?php

use App\AppInstance;
use App\Support\Volumes\Volume;
use App\Support\Volumes\VolumeRules;
use App\Support\Volumes\Volumes;
use Illuminate\Support\Facades\Validator;

// A volume of a profile that declares the given
function ruledVolume(array $definition): Volume
{
    return (new Volumes(new AppInstance, ['data' => $definition + ['size' => 'quota']]))->get('data');
}

it('needs a claim for an existing volume, and a decision between existing and new', function (array $data, bool $valid) {
    expect(Validator::make($data, VolumeRules::rules())->passes())->toBe($valid);
})->with([
    'existing with a claim' => [['volume' => 'existing', 'claim' => 'restored-data'], true],
    'existing without one' => [['volume' => 'existing'], false],
    'new without a claim' => [['volume' => 'new'], true],
    'nothing decided' => [[], false],
    'a claim that is not a name' => [['volume' => 'existing', 'claim' => 'Restored Data'], false],
    'an access mode' => [['volume' => 'new', 'access_mode' => 'ReadWriteMany'], true],
    'an access mode that does not exist' => [['volume' => 'new', 'access_mode' => 'Everywhere'], false],
    'a storage class' => [['volume' => 'new', 'storage_class' => 'longhorn-restored'], true],
    'a storage class that is not a name' => [['volume' => 'new', 'storage_class' => 'Long Horn'], false],
    'a size' => [['volume' => 'new', 'size' => 20], true],
    'a size of nothing' => [['volume' => 'new', 'size' => 0], false],
]);

it('names the fields where they are when the form is part of a bigger one', function () {
    $rules = VolumeRules::rules('storage.');

    expect(array_keys($rules))->toBe(['storage.volume', 'storage.claim', 'storage.access_mode', 'storage.storage_class', 'storage.size']);

    // The rule that needs a claim looks at the decision where it now is
    expect(Validator::make(['storage' => ['volume' => 'existing']], $rules)->passes())->toBeFalse()
        ->and(Validator::make(['storage' => ['volume' => 'existing', 'claim' => 'restored-data']], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['storage' => ['volume' => 'new']], $rules)->passes())->toBeTrue();
});

it('asks a volume that takes a claim what any volume is asked', function () {
    expect(VolumeRules::rulesFor(ruledVolume(['claim' => true])))->toEqual(VolumeRules::rules());
});

it('can only start a volume that takes no claim with a new one', function (array $data, bool $valid) {
    $volume = ruledVolume([]);

    expect(Validator::make($data, VolumeRules::rulesFor($volume))->passes())->toBe($valid);
})->with([
    'existing' => [['volume' => 'existing'], false],
    'existing with a claim' => [['volume' => 'existing', 'claim' => 'restored-data'], false],
    'new' => [['volume' => 'new'], true],
    'new with a claim' => [['volume' => 'new', 'claim' => 'restored-data'], false],
    'new with a size' => [['volume' => 'new', 'size' => 5], true],
]);

it('keeps the same rules under a prefix for a volume that takes no claim', function () {
    $rules = VolumeRules::rulesFor(ruledVolume([]), 'storage.');

    expect(Validator::make(['storage' => ['volume' => 'existing']], $rules)->passes())->toBeFalse()
        ->and(Validator::make(['storage' => ['volume' => 'new']], $rules)->passes())->toBeTrue();
});
