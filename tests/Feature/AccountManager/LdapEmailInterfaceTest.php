<?php

use App\Integrations\ServerManagers\DockerMailServer\Interfaces\LdapEmailInterface;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Email;
use App\Ldap\Models\User as LdapUser;
use App\Mailbox;
use App\OrgDomain;
use App\Services\Email\RecoveryEmailService;
use App\Support\Facades\AccountManager;
use App\Services\Email\MailboxService;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\DB;
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

    // The LDAP mail server the two domains belong to (its interface answers without extra setup)
    DB::table('servers')->insert([[
        'id' => 2, 'app_instance_id' => 0, 'name' => 'Mail', 'address' => 'mail.local', 'host' => 'mail.local',
        'api_key' => 'api_key', 'api_secret' => 'api_secret', 'default_web_server' => 0, 'default_email_server' => 1,
        'default_database_server' => 0, 'internal_address' => 'localhost', 'type' => 'email', 'interface' => 'ldap',
        'settings' => '{}', 'ip' => '127.0.0.1', 'status' => 'active',
    ]]);
    DB::table('org_servers')->insert([['id' => 2, 'organization_id' => $organization->id, 'server_id' => 2]]);

    return [
        new LdapEmailInterface,
        $organization,
        OrgDomain::factory()->create(['organization_id' => $organization->id, 'name' => 'example.com', 'email_server_id' => 2]),
        OrgDomain::factory()->create(['organization_id' => $organization->id, 'name' => 'myexample.com', 'email_server_id' => 2]),
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

it('makes a user\'s own entry a mailbox and turns it back into a plain user', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    [$interface, $organization, $domain] = ldapEmailFixtures($driver);
    $user = AccountManager::users()->find('testing1');
    $dn = Dn::create($organization, 'users', 'testing1');
    $classes = fn () => array_map('strtolower', LdapUser::find($dn)->getAttribute('objectclass'));

    $interface->createUserEmail($user, $domain);
    $interface->createUserEmail($user, $domain);

    $mailbox = LdapUser::find($dn);
    expect($classes())->toContain('postfixbookmailaccount')
        ->and($mailbox->getFirstAttribute('mailQuota'))->toBe('1G')
        ->and($mailbox->getFirstAttribute('mailHomeDirectory'))->toBe('/var/mail/demo/testing1/')
        ->and($mailbox->getFirstAttribute('mailStorageDirectory'))->toBe('maildir:/var/mail/demo/testing1/')
        ->and($mailbox->getFirstAttribute('mail'))->toBe('testing1@example.com');

    $interface->deleteUserEmail($user);

    $plain = LdapUser::find($dn);
    expect($plain->getAttribute('mailquota') ?? [])->toBe([])
        ->and($plain->getAttribute('mailhomedirectory') ?? [])->toBe([])
        ->and($plain->getAttribute('mailstoragedirectory') ?? [])->toBe([])
        ->and($classes())->not->toContain('postfixbookmailaccount')
        ->and($plain->getFirstAttribute('mail'))->toBe('testing1@example.com');
})->with('account_manager_drivers');

it('refuses to make a mailbox of a user that is not in the directory', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    [$interface, $organization, $domain] = ldapEmailFixtures($driver);
    $user = AccountManager::users()->find('testing1');
    LdapUser::find(Dn::create($organization, 'users', 'testing1'))->delete();

    expect(fn () => $interface->createUserEmail($user, $domain))->toThrow(RuntimeException::class);
})->with('account_manager_drivers');

it('hosts the address a user already signs in with without changing their email', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    [, $organization, $domain] = ldapEmailFixtures($driver);
    $user = AccountManager::users()->find('testing1');
    $dn = Dn::create($organization, 'users', 'testing1');
    $mailboxes = app(MailboxService::class);

    $mailbox = $mailboxes->offer($user, $domain, 'testing1');
    $mailboxes->accept($user, $mailbox);

    $entry = LdapUser::find($dn);
    expect($mailbox->fresh()->status)->toBe(Mailbox::ACTIVE)
        ->and($mailbox->fresh()->swapped_at)->toBeNull()
        ->and($entry->getFirstAttribute('mail'))->toBe('testing1@example.com')
        ->and(array_map('strtolower', $entry->getAttribute('objectclass')))->toContain('postfixbookmailaccount');
})->with('account_manager_drivers');

it('switches a user to an organization address, moving their email to the recovery email, and switches back', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    [, $organization, , $other_domain] = ldapEmailFixtures($driver);
    $user = AccountManager::users()->find('testing1');
    $dn = Dn::create($organization, 'users', 'testing1');
    $mailboxes = app(MailboxService::class);
    $recovery = app(RecoveryEmailService::class);

    $mailbox = $mailboxes->offer($user, $other_domain, 'testing1');
    $mailboxes->accept($user, $mailbox);

    $entry = LdapUser::find($dn);
    expect($entry->getFirstAttribute('mail'))->toBe('testing1@myexample.com')
        ->and($recovery->get('testing1'))->toBe('testing1@example.com')
        ->and(array_map('strtolower', $entry->getAttribute('objectclass')))->toContain('postfixbookmailaccount')
        ->and($mailbox->fresh()->previous_mail)->toBe('testing1@example.com')
        ->and($mailbox->fresh()->canBeUndone())->toBeTrue();

    $mailboxes->undo(AccountManager::users()->find('testing1'), $mailbox->fresh());

    $back = LdapUser::find($dn);
    expect($back->getFirstAttribute('mail'))->toBe('testing1@example.com')
        ->and(array_map('strtolower', $back->getAttribute('objectclass')))->not->toContain('postfixbookmailaccount')
        ->and($mailbox->fresh()->status)->toBe(Mailbox::PENDING);
})->with('account_manager_drivers');
