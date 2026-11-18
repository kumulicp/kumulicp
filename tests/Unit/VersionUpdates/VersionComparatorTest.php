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

it('offers any newer tag when an image tracks', function () {
    $tags = ['1.2.4', '1.3.0', '2.0.0'];

    expect((new VersionComparator)->newerFor('1.2.3', $tags))->toBe(['2.0.0', '1.3.0', '1.2.4'])
        ->and((new VersionComparator)->newestFor('1.2.3', $tags, 'track'))->toBe('2.0.0');
});

it('keeps to the same major and minor version for a patch policy', function () {
    $tags = ['1.2.4', '1.2.9', '1.3.0', '2.0.0'];

    expect((new VersionComparator)->newerFor('1.2.3', $tags, 'patch'))->toBe(['1.2.9', '1.2.4']);
});

it('keeps to the same major version for a minor policy', function () {
    $tags = ['1.2.4', '1.3.0', '2.0.0'];

    expect((new VersionComparator)->newerFor('1.2.3', $tags, 'minor'))->toBe(['1.3.0', '1.2.4']);
});

it('never offers a tag for a pinned or ignored image', function () {
    $comparator = new VersionComparator;

    expect($comparator->newerFor('1.2.3', ['1.2.4'], 'pinned'))->toBe([])
        ->and($comparator->newestFor('1.2.3', ['1.2.4'], 'ignore'))->toBeNull();
});

it('compares dated tags and takes the newest of them', function () {
    $tags = ['20261109_101109', '20270101_000000', '20261201_090000', 'latest'];

    expect((new VersionComparator)->newestFor('20261109_101109', $tags))->toBe('20270101_000000');
});

it('keeps the suffix shape under a patch policy', function () {
    $tags = ['8.0.2-debian-12-r0', '8.0.3-debian-12-r0', '8.1.0-debian-12-r0', '8.0.3'];

    expect((new VersionComparator)->newerFor('8.0.1-debian-12-r1', $tags, 'patch'))->toBe(['8.0.3-debian-12-r0', '8.0.2-debian-12-r0']);
});
