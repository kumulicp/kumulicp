<?php

use App\Support\Volumes\Volume;
use App\Support\Volumes\VolumeSpec;

// A volume with only what the test is about
function volumeOf(VolumeSpec $spec, ?bool $enabled): Volume
{
    return new Volume($spec, $enabled, null, null, null, null, null);
}

it('is on or off as it is told to be', function (?bool $enabled, bool $on) {
    expect(volumeOf(new VolumeSpec('redis', 'data', optional: true), $enabled)->on())->toBe($on)
        ->and(volumeOf(VolumeSpec::primary(), $enabled)->on())->toBe($enabled ?? true);
})->with([
    'on' => [true, true],
    'off' => [false, false],
]);

it('is off, when nothing says, if the app can do without it, and on if it can\'t', function () {
    expect(volumeOf(new VolumeSpec('redis', 'data', optional: true), null)->on())->toBeFalse()
        ->and(volumeOf(VolumeSpec::primary(), null)->on())->toBeTrue()
        ->and(volumeOf(new VolumeSpec('uploads', 'data'), null)->on())->toBeTrue();
});

it('names the configuration its settings are in, and where', function () {
    $spec = new VolumeSpec('redis-master', 'data', optional: true, blob: 'redis.master.persistence');

    expect($spec->blob)->toBe('redis.master.persistence');
});

it('refuses a blob that is not a configuration and a path in it', function (string $blob) {
    new VolumeSpec('redis', 'data', blob: $blob);
})->with(['no path' => 'redis', 'no configuration' => '.master', 'a space' => 'redis.master persistence', 'a slash' => 'redis/master'])
    ->throws(InvalidArgumentException::class, 'blob');
