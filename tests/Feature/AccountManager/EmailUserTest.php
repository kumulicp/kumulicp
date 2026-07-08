<?php

use App\Integrations\AccountManagers\Ldap\User as LdapAccountUser;
use App\Ldap\Models\EmailUser;
use App\Ldap\Models\User as LdapUser;
use App\Support\Facades\AccountManager;
use App\User;
use Tests\Support\TestSupports;

it('resolves an EmailUser back to the base User model', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->addUsers();
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);

    $ldapUser = AccountManager::users()->find('testing1')->get();
    expect($ldapUser)->toBeInstanceOf(LdapUser::class);

    // Simulate an EmailUser resolved from a mixed relation (e.g. Group::members()),
    // pointing at the same directory entry as the plain LdapUser above.
    $emailUser = new EmailUser;
    $emailUser->setDn($ldapUser->getDn());
    $emailUser->setAttribute('cn', $ldapUser->getFirstAttribute('cn'));

    $wrapped = new LdapAccountUser($emailUser);

    expect(get_class($wrapped->get()))->toBe(LdapUser::class);
})->with('account_manager_drivers');
