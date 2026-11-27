<?php

use App\Support\Volumes\VolumeSpec;

it('is the volume of every chart that holds the files, sized by the quota', function () {
    $spec = VolumeSpec::primary();

    expect($spec)->workload->toBe('app')->name->toBe('data')->primary->toBeTrue()
        ->size_policy->toBe('quota')->claim_mode->toBe('existing_claim')->optional->toBeFalse()
        ->and($spec->id())->toBe('app/data');
});

it('is identified by its workload and its name', function () {
    expect((new VolumeSpec('redis-master', 'data'))->id())->toBe('redis-master/data');
});

it('has names that are fit to be keys in the settings', function (string $workload, string $name) {
    new VolumeSpec($workload, $name);
})->with([
    'a dot in the workload' => ['redis.master', 'data'],
    'a dot in the name' => ['redis', 'da.ta'],
    'capitals' => ['Redis', 'data'],
    'a slash' => ['redis/master', 'data'],
    'an empty name' => ['redis', ''],
    'a leading dash' => ['redis', '-data'],
])->throws(InvalidArgumentException::class);

it('refuses what it does not know', function (array $arguments) {
    new VolumeSpec('redis', 'data', ...$arguments);
})->with([
    'a size policy' => [['size_policy' => 'shrinking']],
    'a claim mode' => [['claim_mode' => 'borrowed']],
    'a size of nothing' => [['default_size' => 0]],
    'a primary volume that is optional' => [['primary' => true, 'optional' => true]],
])->throws(InvalidArgumentException::class);

it('says where the chart keeps it, in the keys a map has', function () {
    $spec = new VolumeSpec('app', 'data', map: ['path' => 'persistence', 'size' => 'size', 'only_set' => true]);

    expect($spec->map)->toBe(['path' => 'persistence', 'size' => 'size', 'only_set' => true])
        ->and((new VolumeSpec('redis', 'data'))->map)->toBe([]);
});

it('refuses a map with a key it does not have', function () {
    new VolumeSpec('app', 'data', map: ['path' => 'persistence', 'colour' => 'blue']);
})->throws(InvalidArgumentException::class, 'no colour');
