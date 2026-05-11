<?php

/**
 * Example showing the two patterns for account manager tests.
 *
 * Pest style (preferred for new tests):
 *   - Use ->with('account_manager_drivers') to run against both 'db' and 'ldap'.
 *   - Call setupAccountManagerDriver($driver) before seed() in each test.
 *   - Call skipUnlessDriver($driver, 'ldap') for LDAP-only operations.
 *
 * PHPUnit class style (for migrating existing tests):
 *   - Use the TestsWithAccountManagerDrivers trait.
 *   - Use accountManagerDriverProvider() as a @dataProvider.
 *   - Call $this->setupAccountManagerDriver($driver) before seed().
 *   - Call $this->skipIfNotLdap() for LDAP-only operations.
 *   - Call $this->restoreAccountManagerDriver() in tearDown().
 */

use Tests\Support\TestSupports;

// -------------------------------------------------------------------------
// Pest-style pattern
// -------------------------------------------------------------------------

it('adds a user', function (string $driver) {
    setupAccountManagerDriver($driver);
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
    $support->populate();
    $support->addUsers();

    // LDAP-only assertions go here
    expect(true)->toBeTrue();
})->with('account_manager_drivers');
