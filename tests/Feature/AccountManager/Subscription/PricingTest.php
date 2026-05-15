<?php

use App\Enums\PlanEntity;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestSupports;

it('reflects base pricing change', function (string $driver) {
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
    $admin = \App\User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $demoApp = $support->demo_app->instances()->first();

    $support->base_1->payment_enabled = true;
    $support->base_1->save();

    $support->setSubscription($admin->organization, $support->base_1, $support->demo_app_1, $demoApp);

    $base_pricing = Subscription::refresh()->base();

    expect($base_pricing->optionStats('base')['total_price'])->toEqual(1.00);
    expect($base_pricing->optionStats('standard')['total_price'])->toEqual(1.00);
    expect($base_pricing->optionStats('basic')['total_price'])->toEqual(0.00);
    expect($base_pricing->optionStats('storage')['total_price'])->toEqual(0.00);
})->with('account_manager_drivers');

it('recalculates pricing when adding standard users', function (string $driver) {
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

    $support->setSubscription($admin->organization, $support->base_2, $support->demo_app_2, $demoApp);

    $base_pricing = Subscription::base();
    expect($base_pricing->optionStats('standard')['total_price'])->toEqual(2.00);

    grantPermission('testing1', $demoApp->id, ['demo_role']);
    expect($base_pricing->optionStats('standard')['total_price'])->toEqual(4.00);
})->with('account_manager_drivers');

it('recalculates pricing when adding basic users', function (string $driver) {
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
    $admin = \App\User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $demoApp = $support->demo_app->instances()->first();

    $support->setSubscription($admin->organization, $support->base_2, $support->demo_app_2, $demoApp);

    $base_pricing = Subscription::all()->base();
    expect($base_pricing->optionStats('basic')['total_price'])->toEqual(0.00);

    grantPermission('testing1', $demoApp->id, ['basic_demo_role']);
    expect($base_pricing->optionStats('basic')['total_price'])->toEqual(2.00);

    grantPermission('testing2', $demoApp->id, ['basic_demo_role']);
    expect($base_pricing->optionStats('basic')['total_price'])->toEqual(4.00);

    $this->post('/users', [
        'username' => 'testing3',
        'first_name' => 'test',
        'last_name' => 'user3',
        'personal_email' => 'test3@example.com',
    ]);

    grantPermission('testing3', $demoApp->id, ['basic_demo_role']);
    expect($base_pricing->optionStats('basic')['total_price'])->toEqual(4.00);
})->with('account_manager_drivers');

it('recalculates pricing when adding additional storage', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    Http::fake([
        'https://demo-nextcloud.example.com:443/ocs/v1.php/cloud/users/testing1' => ['hey' => 'there'],
    ]);
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

    Organization::setOrganization($admin->organization);
    $support->setSubscription($admin->organization, $support->base_1, $support->demo_app_2, $demoApp);

    grantPermission('testing1', $demoApp->id, ['demo_role']);
    setAdditionalStorage('testing1', $demoApp->id, 1);

    $app_pricing = Subscription::app_instance($demoApp);
    expect($app_pricing->optionStats(PlanEntity::ADDITIONAL_STORAGE)['total_price'])->toEqual(2.00);
    expect(AccountManager::users()->find('testing1')->appStorage($demoApp))->toEqual(4);

    grantPermission('testing2', $demoApp->id, ['demo_role']);
    setAdditionalStorage('testing2', $demoApp->id, 1);

    expect(AccountManager::users()->find('testing2')->appStorage($demoApp))->toEqual(4);
    expect($app_pricing->optionStats(PlanEntity::ADDITIONAL_STORAGE)['total_price'])->toEqual(4.00);
})->with('account_manager_drivers');

it('compiles stripe pricing correctly', function (string $driver) {
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
    $admin = \App\User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $demoApp = $support->demo_app->instances()->first();

    $support->base_1->payment_enabled = true;
    $support->base_1->save();

    $support->setSubscription($admin->organization, $support->base_1);
    $base_pricing = Subscription::refresh()->base();
    $stripe = $base_pricing->stripePricing();

    expect(Arr::get($stripe, 'stripe_base.quantity'))->toEqual(1);
    expect(Arr::get($stripe, 'stripe_basic.quantity'))->toEqual(0);
    expect(Arr::get($stripe, 'stripe_email.quantity'))->toEqual(0);
    expect(Arr::get($stripe, 'stripe_storage.quantity'))->toEqual(0);
    expect(Arr::get($stripe, 'stripe_standard.quantity'))->toEqual(1);
    expect(Arr::get($stripe, 'stripe_application.quantity'))->toEqual(1);
})->with('account_manager_drivers');
