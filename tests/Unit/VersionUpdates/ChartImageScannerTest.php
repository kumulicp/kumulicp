<?php

use App\Support\VersionUpdates\ChartImageScanner;
use App\Support\VersionUpdates\ChartPackage;

// What the chart runs, by where each image is set (see ChartImageScanner::identity())
function scannedImages(array $values, array $metadata = []): array
{
    $images = (new ChartImageScanner)->scan(new ChartPackage($metadata, $values));

    return array_combine(array_map(fn ($image) => ChartImageScanner::identity($image), $images), $images);
}

it('finds the image blocks of a chart at any depth, with where they are set', function () {
    $images = scannedImages([
        'image' => ['registry' => 'docker.io', 'repository' => 'nextcloud', 'tag' => '29.0.1', 'pullPolicy' => 'IfNotPresent'],
        'imaginary' => ['enabled' => false, 'image' => ['registry' => 'docker.io', 'repository' => 'h2non/imaginary', 'tag' => '1.2.4']],
        'synapse' => ['image' => ['registry' => 'ghcr.io', 'repository' => 'element-hq/synapse', 'tag' => 'v1.120.0', 'digest' => 'sha256:abc']],
        'replicaCount' => 1,
    ]);

    expect(array_keys($images))->toBe(['image', 'imaginary.image', 'synapse.image'])
        ->and($images['image'])->toMatchArray(['registry' => 'docker.io', 'repository' => 'nextcloud', 'tag' => '29.0.1', 'digest' => null, 'overridable' => true])
        ->and($images['imaginary.image'])->toMatchArray(['repository' => 'h2non/imaginary', 'tag' => '1.2.4'])
        ->and($images['synapse.image'])->toMatchArray(['registry' => 'ghcr.io', 'repository' => 'element-hq/synapse', 'tag' => 'v1.120.0', 'digest' => 'sha256:abc']);
});

it('takes an empty tag, an absent registry and a numeric tag as they are', function () {
    $images = scannedImages([
        'image' => ['repository' => 'app', 'tag' => ''],
        'other' => ['image' => ['repository' => 'org/other', 'tag' => 1.25]],
        'third' => ['image' => ['registry' => 'quay.io', 'repository' => 'org/third']],
    ]);

    expect($images['image'])->toMatchArray(['registry' => null, 'tag' => null])
        ->and($images['other.image']['tag'])->toBe('1.25')
        ->and($images['third.image'])->toMatchArray(['registry' => 'quay.io', 'tag' => null]);
});

it('finds images given as a whole reference under a key named image, which can only be recorded', function () {
    $images = scannedImages([
        'proxy' => ['image' => 'ghcr.io/org/proxy:1.2.3'],
        'sidecarImage' => 'quay.io/org/sidecar@sha256:def',
        'localRegistry' => ['init-image' => 'localhost:5000/tools/init:2'],
        'plain' => ['image' => 'nginx:1.25'],
    ]);

    expect($images['proxy.image'])->toMatchArray(['registry' => 'ghcr.io', 'repository' => 'org/proxy', 'tag' => '1.2.3', 'overridable' => false])
        ->and($images['sidecarImage'])->toMatchArray(['registry' => 'quay.io', 'repository' => 'org/sidecar', 'tag' => null, 'digest' => 'sha256:def'])
        ->and($images['localRegistry.init-image'])->toMatchArray(['registry' => 'localhost:5000', 'repository' => 'tools/init', 'tag' => '2'])
        ->and($images['plain.image'])->toMatchArray(['registry' => null, 'repository' => 'nginx', 'tag' => '1.25']);
});

it('leaves out what only looks like an image', function () {
    $images = scannedImages([
        // a repository with nothing to say what it holds
        'source' => ['repository' => 'org/app'],
        // not a container image repository
        'git' => ['repository' => 'https://github.com/org/app', 'tag' => 'v1'],
        'templated' => ['image' => ['repository' => '{{ .Values.repo }}', 'tag' => '1']],
        'templatedReference' => ['image' => '{{ .Values.image }}:1'],
        // bare words, flags and empty values under a key named image
        'bare' => ['image' => 'app'],
        'flag' => ['image' => true],
        'empty' => ['image' => ''],
        'spaced' => ['image' => 'not an image:1'],
        // lists have no place to write to
        'containers' => [['name' => 'a', 'image' => 'ghcr.io/org/a:1'], ['image' => ['repository' => 'org/b', 'tag' => '1']]],
        // a dot in a key can't be told from a path
        'a.b' => ['image' => ['repository' => 'org/dotted', 'tag' => '1']],
    ]);

    expect($images)->toBe([]);
});

it('stops at a depth that cannot be a chart', function () {
    $values = ['image' => ['repository' => 'org/deep', 'tag' => '1']];
    for ($i = 0; $i < 12; $i++) {
        $values = ['level' => $values];
    }

    expect(scannedImages($values))->toBe([]);
});

it('lists the images a chart gives in its annotation that its values do not have', function () {
    $images = scannedImages(
        ['image' => ['registry' => 'docker.io', 'repository' => 'nextcloud', 'tag' => '29.0.1']],
        ['annotations' => ['artifacthub.io/images' => "- name: nextcloud\n  image: docker.io/library/nextcloud:29.0.1\n- name: helper\n  image: ghcr.io/org/helper:3.1\n- name: bare\n  image: busybox\n"]]
    );

    expect(array_keys($images))->toBe(['image', '@ghcr.io/org/helper', '@busybox'])
        ->and($images['@ghcr.io/org/helper'])->toMatchArray(['path' => '', 'label' => 'helper', 'registry' => 'ghcr.io', 'repository' => 'org/helper', 'tag' => '3.1', 'overridable' => false])
        ->and($images['@busybox'])->toMatchArray(['repository' => 'busybox', 'tag' => null]);
});

it('does not mind a chart annotation it cannot read', function () {
    expect(scannedImages(['image' => ['repository' => 'org/app', 'tag' => '1']], ['annotations' => ['artifacthub.io/images' => "- : [broken\n  image"]]))->toHaveCount(1)
        ->and(scannedImages([], ['annotations' => ['artifacthub.io/images' => 'not a list']]))->toBe([])
        ->and(scannedImages([], ['annotations' => ['artifacthub.io/images' => "- name: x\n  image: ''\n- just a string\n"]]))->toBe([]);
});

it('writes an image as a reference, and tells one from another by where it is set', function () {
    $image = ['path' => 'a.image', 'registry' => 'ghcr.io', 'repository' => 'org/app', 'tag' => '1.0', 'digest' => 'sha256:abc'];

    expect(ChartImageScanner::reference($image))->toBe('ghcr.io/org/app:1.0@sha256:abc')
        ->and(ChartImageScanner::reference(['registry' => null, 'repository' => 'app', 'tag' => null, 'digest' => null]))->toBe('app')
        ->and(ChartImageScanner::identity($image))->toBe('a.image')
        ->and(ChartImageScanner::identity(['path' => '', 'registry' => 'docker.io', 'repository' => 'library/redis', 'tag' => '7', 'digest' => null]))
        ->toBe(ChartImageScanner::identity(['path' => '', 'registry' => null, 'repository' => 'redis', 'tag' => null, 'digest' => null]));
});
