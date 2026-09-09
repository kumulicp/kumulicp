<?php

use App\AppPlan;
use App\AppVersion;
use App\Organization;
use App\Support\Facades\Application;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\TestSupports;

/**
 * AppInstanceService::configuration() resolves, in order: instance-level
 * override, instance-level persistent/personalized value, plan-level value,
 * then the app profile's declared default. A blank ('') value explicitly
 * set at the plan level must be respected, not treated as "unset" and
 * silently replaced by the profile default.
 */
function activateDemoAppInstanceForConfigTest(): array
{
    $support = new TestSupports;
    $support->seed();

    $organization = Organization::find(1);

    if (! Application::isRegistered('demo_app')) {
        Application::register(new DemoAppProfile);
    }

    $app = Application::initialize('demo_app');
    $plan = AppPlan::factory()->create();
    $version = AppVersion::factory()->create(['application_id' => $app->id]);

    $app_instance = Application::activate($organization, $app, $version, $plan);
    Application::instance($app_instance->get())->status = 'active';
    Application::instance($app_instance->get())->save();

    return [Application::instance($app_instance->get()), $plan];
}

it('falls back to the profile default when the plan has no value set', function () {
    [$app_instance] = activateDemoAppInstanceForConfigTest();

    expect($app_instance->configuration('non-persistent-value'))->toBe('default-non-persistent');
});

it('respects a real plan-level value over the profile default', function () {
    [$app_instance, $plan] = activateDemoAppInstanceForConfigTest();

    $plan->settings = ['configurations' => ['non-persistent-value' => 'custom-value']];
    $plan->save();

    expect(Application::instance($app_instance->get())->configuration('non-persistent-value'))->toBe('custom-value');
});

it('respects a blank plan-level value instead of falling back to the profile default', function () {
    [$app_instance, $plan] = activateDemoAppInstanceForConfigTest();

    $plan->settings = ['configurations' => ['non-persistent-value' => '']];
    $plan->save();

    expect(Application::instance($app_instance->get())->configuration('non-persistent-value'))->toBe('');
});
