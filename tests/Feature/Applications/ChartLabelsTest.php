<?php

use App\AppPlan;
use App\AppVersion;
use App\Integrations\ServerManagers\Rancher\Charts\Ingress\RedirectChart as IngressRedirectChart;
use App\Integrations\ServerManagers\Rancher\Charts\Middleware\RedirectChart as MiddlewareRedirectChart;
use App\Integrations\ServerManagers\Rancher\Charts\NextcloudChart;
use App\Organization;
use App\Support\Facades\Application;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\Applications\DemoHelmChart;

function chartLabelsAppInstance(Organization $organization)
{
    Application::register(new DemoAppProfile);
    $app = Application::initialize('demo_app');
    Application::roles($app);

    $version = AppVersion::factory()->create(['application_id' => $app->id]);
    $app_plan = AppPlan::factory()->create(['application_id' => $app->id]);

    return Application::activate($organization, $app, $version, $app_plan)->get();
}

it('labels charts with the app and instance they came from', function () {
    $organization = Organization::factory()->create();
    $app_instance = chartLabelsAppInstance($organization);

    $expected = [
        'kumulicp.io/app' => $app_instance->application->slug,
        'kumulicp.io/instance' => $app_instance->name,
    ];

    $chart = new DemoHelmChart($organization, $app_instance);

    expect($chart->labels())->toBe($expected);
    expect($chart->valuesWithAdditionalConfigs()['commonLabels'])->toBe($expected);

    $nextcloud = new NextcloudChart($organization, $app_instance);
    $nextcloud_labels = $nextcloud->labelValues();

    expect($nextcloud_labels['podLabels'])->toBe($expected);
    expect($nextcloud_labels['deploymentLabels'])->toBe($expected);
    expect($nextcloud_labels['ingress']['labels'])->toBe($expected);

    expect((new IngressRedirectChart($organization, $app_instance))->values()['metadata']['labels'])->toBe($expected);
    expect((new MiddlewareRedirectChart($organization, $app_instance))->values()['metadata']['labels'])->toBe($expected);
});

it('sanitizes label values to be valid kubernetes labels', function () {
    $organization = Organization::factory()->create();
    $app_instance = chartLabelsAppInstance($organization);
    $app_instance->name = '_My App/Instance '.str_repeat('x', 80).'-';

    $label = (new DemoHelmChart($organization, $app_instance))->labels()['kumulicp.io/instance'];

    expect($label)->toMatch('/^[A-Za-z0-9]([A-Za-z0-9_.-]*[A-Za-z0-9])?$/');
    expect(strlen($label))->toBeLessThanOrEqual(63);
});
