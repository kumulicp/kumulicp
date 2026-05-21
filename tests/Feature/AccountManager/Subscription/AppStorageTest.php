<?php

use App\Support\Facades\AccountManager;
use App\Support\Facades\Application;
use Tests\Support\TestSupports;

it('calculates total app instance storage', function (string $driver, string $plan_type) {
    skipUnlessDriver('ldap', $driver);
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();
    $support->createDemoAppPlans();
    $support->createBase2Plan();
    $support->addUsers();
    $admin = \App\User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $demoApp = $support->demo_app->instances()->first();

    $support->setSubscription($admin->organization, $support->basePlan2OfType($plan_type), $support->demo_app_2, $demoApp);

    Application::roles($support->demo_app);
    expect(Application::instance($demoApp)->storage()->calculateTotalAppStorage())->toBe(2);

    grantPermission('testing1', $demoApp->id, ['demo_role']);
    expect(Application::instance($demoApp)->storage()->calculateTotalAppStorage())->toBe(4);

    setAdditionalStorage('testing1', $demoApp->id, 2);

    expect(Application::instance($demoApp)->storage()->calculateTotalAppStorage())->toBe(8);
    expect(Application::instance($demoApp)->storage()->totalAppStorage())->toBe(8);
})->with('account_manager_drivers')->with('plan_types');

it('calculates standard and basic user storage', function (string $driver, string $plan_type) {
    skipUnlessDriver('ldap', $driver);
    $this->withoutExceptionHandling();
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();
    $support->createDemoAppPlans();
    $support->createBase2Plan();
    $support->addUsers();
    $admin = \App\User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $demoApp = $support->demo_app->instances()->first();

    $support->setSubscription($admin->organization, $support->basePlan1OfType($plan_type), $support->demo_app_1, $demoApp);

    grantPermission('testing1', $demoApp->id, ['demo_role']);
    expect(AccountManager::users()->find('testing1')->appStorage($demoApp))->toBe(1);

    grantPermission('testing1', $demoApp->id, ['basic_demo_role']);
    expect(AccountManager::users()->find('testing1')->appStorage($demoApp))->toBe(0.5);

    $support->setSubscription($admin->organization, $support->basePlan1OfType($plan_type), $support->demo_app_2, $demoApp);
    expect(AccountManager::users()->find('testing1')->appStorage($demoApp))->toBe(1);

    grantPermission('testing1', $demoApp->id, ['demo_role']);
    expect(AccountManager::users()->find('testing1')->appStorage($demoApp))->toBe(2);
})->with('account_manager_drivers')->with('plan_types');
