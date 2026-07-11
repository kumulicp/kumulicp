<?php

use App\Support\Facades\AccountManager;
use App\User;
use Tests\Support\TestSupports;

it('enforces max standard user limit', function (string $driver, string $plan_type) {
    skipUnlessDriver('ldap', $driver);
    $this->withoutExceptionHandling();
    $this->followingRedirects();
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();
    $support->createDemoAppPlans();
    $support->createBase2Plan();
    $support->addUsers();
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $demoApp = $support->demo_app->instances()->first();

    $support->setSubscription($admin->organization, $support->basePlanWithStandardMax($plan_type, 1), $support->demo_app_1, $demoApp);

    expect(AccountManager::users()->find('testing1')->canAccessApp($demoApp))->toBeFalse();
    expect(AccountManager::users()->find('testing2')->canAccessApp($demoApp))->toBeFalse();

    grantPermission('testing1', $demoApp->id, ['demo_role']);
    grantPermission('testing2', $demoApp->id, ['demo_role']);

    expect(AccountManager::users()->find('testing1')->canAccessApp($demoApp))->toBeTrue();
    expect(AccountManager::users()->find('testing2')->canAccessApp($demoApp))->toBeFalse();

    $support->setSubscription($admin->organization, $support->basePlanWithStandardMax($plan_type, 2), $support->demo_app_2, $demoApp);

    grantPermission('testing2', $demoApp->id, ['demo_role']);
    expect(AccountManager::users()->find('testing2')->canAccessApp($demoApp))->toBeTrue();
})->with('account_manager_drivers')->with('plan_types');

it('enforces max additional storage limit', function (string $driver, string $plan_type) {
    skipUnlessDriver('ldap', $driver);
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();
    $support->createDemoAppPlans();
    $support->createBase2Plan();
    $support->addUsers();
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $demoApp = $support->demo_app->instances()->first();

    $support->setSubscription($admin->organization, $support->basePlan1OfType($plan_type), $support->demo_app_2, $demoApp);

    grantPermission('testing1', $demoApp->id, ['demo_role']);
    setAdditionalStorage('testing1', $demoApp->id, 1);
    expect(AccountManager::users()->find('testing1')->appStorage($demoApp))->toBe(4);

    grantPermission('testing2', $demoApp->id, ['demo_role']);
    setAdditionalStorage('testing2', $demoApp->id, 1);
    expect(AccountManager::users()->find('testing2')->appStorage($demoApp))->toBe(4);

    setAdditionalStorage('testing2', $demoApp->id, 100);
    expect(AccountManager::users()->find('testing2')->appStorage($demoApp))->toBe(4);
})->with('account_manager_drivers')->with('plan_types');

it('enforces max basic user limit', function (string $driver, string $plan_type) {
    skipUnlessDriver('ldap', $driver);
    $this->withoutExceptionHandling();
    $this->followingRedirects();
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();
    $support->createDemoAppPlans();
    $support->createBase2Plan();
    $support->addUsers();
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $demoApp = $support->demo_app->instances()->first();

    $support->setSubscription($admin->organization, $support->basePlan1OfType($plan_type), $support->demo_app_1, $demoApp);

    grantPermission('testing1', $demoApp->id, ['basic_demo_role']);
    grantPermission('testing2', $demoApp->id, ['basic_demo_role']);

    expect(AccountManager::users()->find('testing1')->canAccessApp($demoApp))->toBeTrue();
    expect(AccountManager::users()->find('testing1')->appUserAccessType($demoApp))->toBe('basic');
    expect(AccountManager::users()->find('testing2')->canAccessApp($demoApp))->toBeFalse();
    expect(AccountManager::users()->find('testing2')->appUserAccessType($demoApp))->toBe('none');

    $support->setSubscription($admin->organization, $support->basePlan2OfType($plan_type), $support->demo_app_2, $demoApp);

    grantPermission('testing2', $demoApp->id, ['basic_demo_role']);
    expect(AccountManager::users()->find('testing2')->canAccessApp($demoApp))->toBeTrue();
    expect(AccountManager::users()->find('testing2')->appUserAccessType($demoApp))->toBe('basic');
})->with('account_manager_drivers')->with('plan_types');

it('updates user maximums when switching between plan types', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    $this->withoutExceptionHandling();
    $this->followingRedirects();
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();
    $support->createDemoAppPlans();
    $support->createBase2Plan();
    $support->addUsers();
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $demoApp = $support->demo_app->instances()->first();

    // App-type plan: demo_app_1 has max=1 standard user
    $support->setSubscription($admin->organization, $support->base_1, $support->demo_app_1, $demoApp);

    grantPermission('testing1', $demoApp->id, ['demo_role']);
    grantPermission('testing2', $demoApp->id, ['demo_role']);
    expect(AccountManager::users()->find('testing1')->canAccessApp($demoApp))->toBeTrue();
    expect(AccountManager::users()->find('testing2')->canAccessApp($demoApp))->toBeFalse();

    // Switch to a package-type plan allowing 2 standard users on top of the
    // seeded admin, who always holds a standard slot org-wide under package plans.
    $support->setSubscription($admin->organization, $support->basePlanWithStandardMax('package', 2), $support->demo_app_2, $demoApp);

    // Permission previously denied under the max=1 limit isn't retroactively
    // granted by raising the limit — it must be re-requested.
    grantPermission('testing2', $demoApp->id, ['demo_role']);
    expect(AccountManager::users()->find('testing2')->canAccessApp($demoApp))->toBeTrue();
})->with('account_manager_drivers');
