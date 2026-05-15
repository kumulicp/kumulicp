<?php

/**
 * Example showing the Pest pattern for account manager tests.
 *
 * - Use ->with('account_manager_drivers') to run against both 'db' and 'ldap'.
 * - Call setupAccountManagerDriver($driver) before seed() in each test.
 * - Call skipUnlessDriver($driver, 'ldap') for LDAP-only operations.
 */

use Tests\Support\TestSupports;

it('adds a user', function (string $driver) {
    setupAccountManagerDriver($driver);
    dump($driver);
    $support = new TestSupports;
    $support->seed();
    $support->addUsers();

    $user = \App\Support\Facades\AccountManager::users()->find('testing1');
    expect($user)->not->toBeNull();
})->with('account_manager_drivers');

it('updates app permissions (LDAP only)', function (string $driver) {
    skipUnlessDriver($driver, 'ldap');
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();
    $support->addUsers();

    // LDAP-only assertions go here
    expect(true)->toBeTrue();
})->with('account_manager_drivers');
