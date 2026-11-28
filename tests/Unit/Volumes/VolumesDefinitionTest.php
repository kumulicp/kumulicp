<?php

use App\AppInstance;
use App\Support\Volumes\Volumes;

// The volumes of a profile that declares the given, for an instance that is not saved
function declaredVolumes(array $definitions): Volumes
{
    return new Volumes(new AppInstance, $definitions);
}

it('is what the profile declares, by name and in its order', function () {
    $volumes = declaredVolumes([
        'data' => ['size' => 'quota', 'claim' => true],
        'redis-master' => ['size' => 8, 'optional' => true],
        'mariadb' => ['size' => 8, 'optional' => true],
    ]);

    expect(array_keys($volumes->all()))->toBe(['data', 'redis-master', 'mariadb'])
        ->and($volumes->has('mariadb'))->toBeTrue()
        ->and($volumes->has('redis'))->toBeFalse();
});

it('says what each volume is, from what the profile says', function () {
    $volumes = declaredVolumes([
        'data' => ['label' => 'admin.volumes.data', 'size' => 'quota', 'claim' => true],
        'redis-master' => ['size' => 8, 'optional' => true],
    ]);

    expect($volumes->get('data'))->followsQuota()->toBeTrue()->takesClaim()->toBeTrue()->optional()->toBeFalse()->label()->toBe('admin.volumes.data')
        ->and($volumes->get('redis-master'))->followsQuota()->toBeFalse()->takesClaim()->toBeFalse()->optional()->toBeTrue()
        // A volume with no label is named after itself
        ->label()->toBe('admin.volumes.redis-master');
});

it('is off when it is optional and the profile does not say, and on when it is not', function () {
    $volumes = declaredVolumes(['data' => ['size' => 'quota'], 'redis' => ['size' => 8, 'optional' => true], 'mariadb' => ['size' => 8, 'optional' => true, 'enabled' => true]]);

    expect($volumes->get('data')->enabled())->toBeTrue()
        ->and($volumes->get('redis')->enabled())->toBeFalse()
        ->and($volumes->get('mariadb')->enabled())->toBeTrue();
});

it('has no volume the profile did not declare', function () {
    declaredVolumes(['data' => ['size' => 'quota']])->get('redis-master');
})->throws(InvalidArgumentException::class, "no volume 'redis-master'");

it('is none, for a profile that declares none', function () {
    expect(declaredVolumes([])->all())->toBe([]);
});

it('refuses a profile that declares a volume that can\'t be kept', function (array $definitions, string $message) {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage($message);

    declaredVolumes($definitions);
})->with([
    'a name with a dot' => [['redis.master' => ['size' => 8]], 'lowercase letters'],
    'a name in capitals' => [['Redis' => ['size' => 8]], 'lowercase letters'],
    'no size' => [['redis' => ['optional' => true]], "'quota' or a number of Gi"],
    'a size of nothing' => [['redis' => ['size' => 0]], "'quota' or a number of Gi"],
    'a size that is a string' => [['redis' => ['size' => '8Gi']], "'quota' or a number of Gi"],
    'a key it does not have' => [['redis' => ['size' => 8, 'colour' => 'blue']], 'has no colour'],
    'a volume that is not an array' => [['redis' => 8], 'not described by an array'],
]);

it('is the smallest size, not 0, when the subscription gives the app nothing yet, and keeps nothing', function () {
    // A store that is told the quota is not worked out
    $volumes = new class(new AppInstance, ['data' => ['size' => 'quota']]) extends Volumes
    {
        public function quota(): int
        {
            return 0;
        }
    };

    expect($volumes->get('data')->size())->toBe(1);
});
