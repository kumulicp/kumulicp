<?php

use App\Support\VersionUpdates\ChartPackage;
use Tests\Support\VersionUpdates\ChartArchive;

it('reads Chart.yaml, values and schema from a packaged chart without helm', function () {
    $package = ChartPackage::fromArchive(ChartArchive::make(
        'demo',
        '1.2.0',
        ['image' => ['tag' => '']],
        ['kubeVersion' => '>=1.25.0-0', 'annotations' => ['catalog.cattle.io/rancher-version' => '>= 2.9.0-0']],
        ['type' => 'object'],
    ));

    expect($package->version())->toBe('1.2.0')
        ->and($package->apiVersion())->toBe('v2')
        ->and($package->kubeVersion())->toBe('>=1.25.0-0')
        ->and($package->annotation('catalog.cattle.io/rancher-version'))->toBe('>= 2.9.0-0')
        ->and($package->values)->toBe(['image' => ['tag' => '']])
        ->and($package->schema)->toBe(['type' => 'object']);
});

it('rejects data that is not a chart', function () {
    ChartPackage::fromArchive('not a tarball');
})->throws(RuntimeException::class);
