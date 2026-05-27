<?php

use App\Events\Users\UserStorageUpdated;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Application;
use App\User;
use Illuminate\Support\Facades\Event;
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
    $admin = User::where('username', 'demo')->firstOrFail();
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
    $admin = User::where('username', 'demo')->firstOrFail();
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

it('updates total app storage when switching between plan types', function (string $driver) {
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

    // App-type plan: base storage=1, standard user storage=1
    $support->setSubscription($admin->organization, $support->base_1, $support->demo_app_1, $demoApp);
    Application::roles($support->demo_app);
    grantPermission('testing1', $demoApp->id, ['demo_role']);
    expect(Application::instance($demoApp)->storage()->calculateTotalAppStorage())->toBe(2); // base=1 + user=1

    // Switch to package-type plan: base storage=2, standard user storage=2
    $support->setSubscription($admin->organization, $support->base_2, $support->demo_app_2, $demoApp);
    expect(Application::instance($demoApp)->storage()->calculateTotalAppStorage())->toBe(4); // base=2 + user=2
})->with('account_manager_drivers');

it('dispatches a storage update scoped to the specific user and app when additional storage changes', function (string $driver) {
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();
    $support->createDemoAppPlans();
    $support->addUsers();
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $demoApp = $support->demo_app->instances()->first();

    $support->setSubscription($admin->organization, $support->base_1, $support->demo_app_1, $demoApp);

    Event::fake([UserStorageUpdated::class]);

    setAdditionalStorage('testing1', $demoApp->id, 2);

    Event::assertDispatchedTimes(UserStorageUpdated::class, 1);
    Event::assertDispatched(UserStorageUpdated::class, function ($event) use ($admin, $demoApp) {
        return $event->organization->is($admin->organization)
            && $event->user_id === 'testing1'
            && $event->app_instance->is($demoApp);
    });

    // Re-applying the same quantity is a no-op, so nothing should dispatch again.
    Event::fake([UserStorageUpdated::class]);
    setAdditionalStorage('testing1', $demoApp->id, 1);
    Event::assertNotDispatched(UserStorageUpdated::class);

    // Removing the additional storage dispatches again, still scoped to this user and app.
    Event::fake([UserStorageUpdated::class]);
    setAdditionalStorage('testing1', $demoApp->id, 0);
    Event::assertDispatched(UserStorageUpdated::class, function ($event) use ($admin, $demoApp) {
        return $event->organization->is($admin->organization)
            && $event->user_id === 'testing1'
            && $event->app_instance->is($demoApp);
    });
})->with('account_manager_drivers');
