<?php

use App\AppPlan;
use App\AppVersion;
use App\Integrations\ServerManagers\Rancher\Charts\GenericChart;
use App\Organization;
use App\Support\Facades\Application;

it('builds generic-app chart values from an image-only app instance', function () {
    $organization = Organization::factory()->create();

    $app = Application::initialize('generic');
    Application::roles($app);

    $version = AppVersion::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'chart_name' => 'generic-app',
            'chart_version' => '0.1.0',
            'helm_repo_name' => 'https://example.com/charts',
            'image_repo_name' => 'library/my-app',
            'image_registry' => 'docker.io',
            'port' => 9000,
        ],
    ]);

    $app_plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'base' => ['price' => 0, 'storage' => 2, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
            'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
            'configurations' => [
                'env' => ['FOO' => 'bar'],
                'persistence-enabled' => true,
                'ingress-enabled' => true,
                'ingress-tls' => true,
            ],
        ],
    ]);

    $app_instance_service = Application::activate($organization, $app, $version, $app_plan);
    $app_instance = $app_instance_service->get();

    $chart = new GenericChart($organization, $app_instance);
    $values = $chart->values();

    expect($values['image']['repository'])->toBe('library/my-app');
    expect($values['image']['registry'])->toBe('docker.io');
    expect($values['image']['tag'])->toBe($version->name);
    expect($values['port'])->toBe(9000);
    expect($values['env'])->toContain(['name' => 'FOO', 'value' => 'bar']);
    expect($values['persistence']['enabled'])->toBeTrue();
    expect($values['persistence']['size'])->toBe('2Gi');
    expect($values['ingress']['enabled'])->toBeTrue();
    expect($values['ingress']['tls'])->toBeTrue();
    expect($values['ingress']['annotations'])->toHaveKey('cert-manager.io/cluster-issuer');
});

it('defaults the port to 8080 when the version does not set one', function () {
    $organization = Organization::factory()->create();

    $app = Application::initialize('generic');
    Application::roles($app);

    $version = AppVersion::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'image_repo_name' => 'library/my-app',
        ],
    ]);

    $app_plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'base' => ['price' => 0, 'storage' => 1, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
            'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
        ],
    ]);

    $app_instance_service = Application::activate($organization, $app, $version, $app_plan);
    $app_instance = $app_instance_service->get();

    $chart = new GenericChart($organization, $app_instance);
    $values = $chart->values();

    expect($values['port'])->toBe(8080);
    expect($values['persistence']['enabled'])->toBeFalse();
    expect($values['ingress']['enabled'])->toBeFalse();
});
