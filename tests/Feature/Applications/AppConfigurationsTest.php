<?php

use App\AppPlan;
use App\AppVersion;
use App\Organization;
use App\Support\Facades\Application;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\Applications\DemoHelmChart;

it('handles configurations, features, and helm chart values', function () {
    $organization = Organization::factory()->create();

    Application::register(new DemoAppProfile);
    $app = Application::initialize('demo_app');
    Application::roles($app);

    $roles = [];
    foreach ($app->roles()->get() as $role) {
        $roles[] = $role->id;
    }

    $version = AppVersion::factory()->create(['application_id' => $app->id]);
    $version->roles = ['order' => $roles];
    $version->save();

    $app_plan = AppPlan::factory()->create([
        'application_id' => $app->id,
        'settings' => [
            'base' => ['price' => 0, 'storage' => 1, 'price_id' => null],
            'basic' => ['max' => 1, 'name' => 'Basic', 'price' => 0, 'amount' => 1, 'storage' => 1, 'price_id' => null],
            'storage' => ['max' => 1, 'price' => 0, 'amount' => 1, 'price_id' => null],
            'standard' => ['max' => 1, 'price' => 0, 'storage' => 1, 'price_id' => null],
            'features' => [
                'enabled-feature' => [
                    'name' => 'enabled-feature',
                    'price' => null,
                    'status' => 'enabled',
                    'settings' => [],
                    'price_id' => null,
                ],
                'optional-feature' => [
                    'name' => 'optional-feature',
                    'price' => null,
                    'status' => 'optional',
                    'settings' => [],
                    'price_id' => null,
                ],
                'disabled-feature' => [
                    'name' => 'disabled-feature',
                    'price' => null,
                    'status' => 'disabled',
                    'settings' => [],
                    'price_id' => null,
                ],
            ],
            'configurations' => [
                'fake-config' => false,
                'persistent-value' => 'plan-persistent-value',
                'non-persistent-value' => 'plan-non-persistent-value',
                'override-value' => 'plan-override-value',
            ],
        ],
    ]);

    $app_instance_service = Application::activate($organization, $app, $version, $app_plan);
    $app_instance = $app_instance_service->get();

    expect($app_instance->setting('configurations.persistent-value'))->toBe('plan-persistent-value');
    expect($app_instance->setting('configurations.non-persistent-value'))->toBeNull();
    expect($app_instance->setting('configurations.override-value'))->toBeNull();
    expect($app_instance->setting('configurations.fake-config'))->toBeNull();

    $features = Application::instance($app_instance)->features();

    expect($features->isActive('enabled-feature'))->toBeTrue();
    expect($features->optional())->toHaveKey('optional-feature');
    expect($features->isActive('optional-feature'))->toBeFalse();
    expect($features->isActive('disabled-feature'))->toBeFalse();

    $app_instance->updateSetting('override.override-value', 'overridden-value');
    $app_instance->refresh();

    $chart = new DemoHelmChart($organization, $app_instance);
    $values = $chart->values();

    expect($values['persistentValue'])->toBe('plan-persistent-value');
    expect($values['nonPersistentValue'])->toBe('plan-non-persistent-value');
    expect($values['overrideValue'])->toBe('overridden-value');
});
