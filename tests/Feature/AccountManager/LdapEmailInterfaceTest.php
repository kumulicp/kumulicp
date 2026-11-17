<?php

use App\Integrations\ServerManagers\DockerMailServer\Interfaces\LdapEmailInterface;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Email;
use App\Ldap\Models\User as LdapUser;
use App\OrgDomain;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Settings;
use App\User;
use Tests\Support\TestSupports;

/**
 * The interface, the organization and two domains that only differ by a prefix
 * (example.com and myexample.com), against the LDAP directory.
 *
 * @return array{LdapEmailInterface, \App\Organization, OrgDomain, OrgDomain}
 */
function ldapEmailFixtures(string $driver): array
{
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->addUsers();
    $admin = User::where('username', 'demo')->firstOrFail();
    test()->actingAs($admin);
    $organization = $admin->organization;

    return [
        new LdapEmailInterface,
        $organization,
        OrgDomain::factory()->create(['organization_id' => $organization->id, 'name' => 'example.com']),
        OrgDomain::factory()->create(['organization_id' => $organization->id, 'name' => 'myexample.com']),
    ];
}

it('keeps the quota unit when a mailbox is updated and hashes its password with SHA-512 crypt', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    [$interface, $organization, $domain] = ldapEmailFixtures($driver);
    $dn = Dn::create($organization, 'emails', 'jane@example.com');

    $interface->addEmail($domain, 'Jane', 'jane', 'Passw0rd!');

    $created = Email::find($dn);
    expect($created->getFirstAttribute('mailQuota'))->toBe('1G')
        ->and($created->getFirstAttribute('userPassword'))->toStartWith('{CRYPT}$6$');

    $interface->updateEmail($domain, 'Jane Doe', 'jane@example.com', 'N3wPassw0rd!');

    $updated = Email::find($dn);
    expect($updated->getFirstAttribute('mailQuota'))->toBe('1G')
        ->and($updated->getFirstAttribute('displayName'))->toBe('Jane Doe')
        ->and($updated->getFirstAttribute('userPassword'))->toStartWith('{CRYPT}$6$')
        ->and($updated->getFirstAttribute('userPassword'))->not->toBe($created->getFirstAttribute('userPassword'));
})->with('account_manager_drivers');

it('lists only the mailboxes at exactly the domain, not ones whose domain merely ends the same', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    [$interface, , $domain, $other_domain] = ldapEmailFixtures($driver);

    $interface->addEmail($domain, 'Jane', 'jane', 'Passw0rd!');
    $interface->addEmail($other_domain, 'Jo', 'jo', 'Passw0rd!');

    expect(array_column($interface->emailList($domain), 'email'))->toBe(['jane@example.com'])
        ->and(array_column($interface->emailList($other_domain), 'email'))->toBe(['jo@myexample.com']);
})->with('account_manager_drivers');

it('adds and removes a user\'s mailboxes, leaving a plain user once the last one is gone', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    [$interface, $organization, $domain, $other_domain] = ldapEmailFixtures($driver);
    $user = AccountManager::users()->find('testing1');
    $dn = Dn::create($organization, 'users', 'testing1');
    $classes = fn () => array_map('strtolower', LdapUser::find($dn)->getAttribute('objectclass'));

    $interface->createUserEmail($user, $domain);
    $interface->createUserEmail($user, $domain);
    $interface->createUserEmail($user, $other_domain);

    $with_mail = LdapUser::find($dn);
    expect($with_mail->getAttribute('orgmail'))->toEqualCanonicalizing(['testing1@example.com', 'testing1@myexample.com'])
        ->and($classes())->toContain('postfixbookmailaccount')
        ->and($with_mail->getFirstAttribute('mailQuota'))->toBe('1G');

    $interface->deleteUserEmail($user, 'TESTING1@example.com');

    expect(LdapUser::find($dn)->getAttribute('orgmail'))->toEqual(['testing1@myexample.com'])
        ->and($classes())->toContain('postfixbookmailaccount');

    $interface->deleteUserEmail($user, 'testing1@myexample.com');

    $plain = LdapUser::find($dn);
    expect($plain->getAttribute('orgmail') ?? [])->toBe([])
        ->and($plain->getAttribute('mailquota') ?? [])->toBe([])
        ->and($plain->getAttribute('mailhomedirectory') ?? [])->toBe([])
        ->and($plain->getAttribute('mailstoragedirectory') ?? [])->toBe([])
        ->and($classes())->not->toContain('postfixbookmailaccount');
})->with('account_manager_drivers');

it('refuses to add a mailbox to a user that is not in the directory', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    [$interface, $organization, $domain] = ldapEmailFixtures($driver);
    $user = AccountManager::users()->find('testing1');
    LdapUser::find(Dn::create($organization, 'users', 'testing1'))->delete();

    expect(fn () => $interface->createUserEmail($user, $domain))->toThrow(RuntimeException::class);
})->with('account_manager_drivers');

it('reads a user\'s mailboxes back through the mapped org email attribute', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    [$interface, , $domain, $other_domain] = ldapEmailFixtures($driver);
    Settings::update('ldap_org_email', 'orgMail');
    $interface->createUserEmail(AccountManager::users()->find('testing1'), $domain);
    $interface->createUserEmail(AccountManager::users()->find('testing1'), $other_domain);

    $user = AccountManager::users()->find('testing1');

    expect($user->attribute('org_email', 'array'))->toEqualCanonicalizing(['testing1@example.com', 'testing1@myexample.com'])
        ->and($user->attribute('personal_email'))->toBe('testing1@example.com');
})->with('account_manager_drivers');
