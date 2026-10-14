<?php

use App\Support\VersionUpdates\VersionComparator;

it('picks the newest tag with the same shape as the current one', function () {
    $tags = ['33.0.1', '33.0.2', '33.1.0', '34', '34-apache', 'latest', 'stable', '33.1.0-apache', '32.0.9'];

    expect((new VersionComparator)->newest('33.0.1', $tags))->toBe('33.1.0');
});

it('compares suffixed tags only with the same suffix shape', function () {
    $tags = ['8.0.1-debian-12-r1', '8.0.1-debian-12-r3', '8.0.2-debian-12-r0', '8.0.3', '8.0.3-alpine'];

    expect((new VersionComparator)->newer('8.0.1-debian-12-r1', $tags))
        ->toBe(['8.0.2-debian-12-r0', '8.0.1-debian-12-r3']);
});

it('skips pre-releases unless the current version is one', function () {
    $comparator = new VersionComparator;

    expect($comparator->newest('9.0.5', ['9.0.6-rc.1', '9.1.0-beta.2', '9.0.4']))->toBeNull();
    expect($comparator->newest('9.0.6-rc.1', ['9.0.6-rc.2', '9.0.6']))->toBe('9.0.6-rc.2');
});

it('compares numerically, not as strings', function () {
    expect((new VersionComparator)->newest('1.9.0', ['1.10.0', '1.2.0']))->toBe('1.10.0');
});

it('returns nothing for tags without a version number', function () {
    expect((new VersionComparator)->newer('latest', ['1.0.0', 'latest']))->toBe([]);
});

it('only counts a stored candidate as newer when it still is', function () {
    $comparator = new VersionComparator;

    expect($comparator->isNewer('9.0.6', '9.0.5'))->toBeTrue();
    expect($comparator->isNewer('9.0.6', '9.0.6'))->toBeFalse();
    expect($comparator->isNewer(null, '9.0.6'))->toBeFalse();
});
