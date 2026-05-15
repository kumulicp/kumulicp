<?php

use App\Jobs\Applications\UpdateLdapGroups;
use App\Support\Facades\AccountManager;
use Tests\Support\TestSupports;

it('tracks app role access types', function (string $driver) {
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

    $support->setSubscription($admin->organization, $support->base_1, $support->demo_app_1, $demoApp);
    UpdateLdapGroups::dispatch($demoApp);

    $user = AccountManager::users()->find('testing1');
    expect($user->canAccessApp($demoApp))->toBeFalse();

    grantPermission('testing1', $demoApp->id, ['demo_role']);

    expect($user->canAccessApp($demoApp))->toBeTrue();
    expect($user->appUserAccessType($demoApp))->toBe('standard');
})->with('account_manager_drivers');
