<?php

use App\Integrations\Applications\CiviCRMStandalone\CiviCRMStandaloneChart;
use App\Integrations\Applications\HelmForge\HelmForgeChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\GenericChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\Job\JobChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\NextcloudChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\WordpressChart;
use App\Support\Volumes\LegacyVolumeKeys;
use Tests\Support\Applications\DemoHelmChart;

// What a module that builds on Nextcloud's chart has
class PluginNextcloudChart extends NextcloudChart {}

it('is the layout of the chart class that deploys the instance', function (string $chart, ?string $layout) {
    expect(LegacyVolumeKeys::layoutOf($chart))->toBe($layout);
})->with([
    'Nextcloud' => [NextcloudChart::class, 'nextcloud'],
    'WordPress' => [WordpressChart::class, 'wordpress'],
    'CiviCRM' => [CiviCRMStandaloneChart::class, 'civicrm'],
    'a catalog chart' => [HelmForgeChart::class, 'catalog'],
    'the generic chart' => [GenericChart::class, 'generic'],
    'a chart that has none' => [DemoHelmChart::class, null],
    'a job' => [JobChart::class, null],
]);

it('is the layout of a chart that extends one of these, as a plugin\'s does', function () {
    expect(LegacyVolumeKeys::layoutOf(PluginNextcloudChart::class))->toBe('nextcloud');
});
