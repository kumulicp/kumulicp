<?php

namespace Tests\Feature\Admin;

use App\Mailbox;
use App\OrgDomain;
use App\Services\Email\AliasDirectory;
use App\Services\Email\MailboxAliasService;
use App\Services\Email\RecoveryEmailDirectory;
use App\Support\Facades\Settings;
use App\User;
use App\UserRecoveryEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Email\FakeAliasDirectory;
use Tests\Support\Email\FakeRecoveryEmailDirectory;
use Tests\Support\TestSupports;
use Tests\TestCase;

class OrgEmailTest extends TestCase
{
    use RefreshDatabase;

    private FakeRecoveryEmailDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();

        (new TestSupports)->seed();
        $this->actingAs(User::where('username', 'demo')->firstOrFail());

        $this->directory = new FakeRecoveryEmailDirectory;
        $this->app->instance(RecoveryEmailDirectory::class, $this->directory);
    }

    public function test_without_ldap_aliases_are_available_and_there_is_nothing_to_sync()
    {
        config(['account_manager.driver' => 'db']);
        UserRecoveryEmail::create(['username' => 'jane', 'email' => 'jane@example.com']);

        $this->get('/admin/email')->assertInertia(fn ($page) => $page
            ->component('Admin/Email/EmailSync')
            ->where('ldap_enabled', false)
            ->where('aliases.enabled', true)
            ->where('recovery.sync_enabled', false)
            ->where('recovery.count', 1));
    }

    public function test_with_ldap_aliases_stay_disabled_until_an_attribute_is_set()
    {
        config(['account_manager.driver' => 'ldap']);

        $this->get('/admin/email')->assertInertia(fn ($page) => $page
            ->where('ldap_enabled', true)
            ->where('aliases.enabled', false)
            ->where('aliases.attribute', null));

        Settings::update('ldap_aliases', 'proxyAddresses');

        $this->get('/admin/email')->assertInertia(fn ($page) => $page
            ->where('aliases.enabled', true)
            ->where('aliases.attribute', 'proxyAddresses'));
    }

    public function test_the_sync_is_offered_once_ldap_has_a_recovery_email_attribute()
    {
        config(['account_manager.driver' => 'ldap']);
        Settings::update('ldap_recovery_email', 'recoveryMail');

        $this->get('/admin/email')->assertInertia(fn ($page) => $page
            ->where('recovery.attribute', 'recoveryMail')
            ->where('recovery.sync_enabled', true));
    }

    public function test_sync_copies_the_database_to_the_directory()
    {
        config(['account_manager.driver' => 'ldap']);
        Settings::update('ldap_recovery_email', 'recoveryMail');
        UserRecoveryEmail::create(['username' => 'jane', 'email' => 'jane@example.com']);
        $this->directory->entries = ['jane' => 'stale@example.com'];

        $response = $this->post('/admin/email/sync');

        $response->assertRedirect('/admin/email');
        $response->assertSessionHas('success');
        $this->assertSame('jane@example.com', $this->directory->entries['jane']);
        $this->assertSame(1, session('sync_result')['updated']);
    }

    public function test_sync_shows_what_it_found_on_the_page_it_returns_to()
    {
        config(['account_manager.driver' => 'ldap']);
        Settings::update('ldap_recovery_email', 'recoveryMail');
        UserRecoveryEmail::create(['username' => 'gone', 'email' => 'gone@example.com']);

        $this->post('/admin/email/sync')->assertSessionHas('error');

        $this->get('/admin/email')->assertInertia(fn ($page) => $page
            ->where('sync_result.missing', ['gone']));
    }

    public function test_sync_refuses_when_there_is_no_directory_to_sync_with()
    {
        config(['account_manager.driver' => 'db']);
        $this->directory->enabled = false;
        UserRecoveryEmail::create(['username' => 'jane', 'email' => 'jane@example.com']);
        $this->directory->entries = ['jane' => null];

        $response = $this->post('/admin/email/sync');

        $response->assertRedirect('/admin/email');
        $response->assertSessionHas('error');
        $this->assertSame([], $this->directory->writes);
    }

    public function test_the_page_says_how_many_aliases_there_are_and_whether_they_can_be_synced()
    {
        $this->app->instance(AliasDirectory::class, $aliases = new FakeAliasDirectory);
        $aliases->enabled = false;
        $domain = OrgDomain::factory()->create(['organization_id' => User::where('username', 'demo')->first()->organization->id, 'name' => 'corp.org', 'email_enabled' => true, 'email_status' => 'active']);
        $mailbox = Mailbox::create(['organization_id' => $domain->organization_id, 'org_domain_id' => $domain->id, 'local_part' => 'jane', 'address' => 'jane@corp.org', 'status' => Mailbox::ACTIVE]);
        config(['account_manager.driver' => 'db']);
        app(MailboxAliasService::class)->add($mailbox, 'sales', $domain);

        $this->get('/admin/email')->assertInertia(fn ($page) => $page
            ->where('aliases.count', 1)
            ->where('aliases.sync_enabled', false));

        $aliases->enabled = true;

        $this->get('/admin/email')->assertInertia(fn ($page) => $page->where('aliases.sync_enabled', true));
    }

    public function test_sync_also_copies_aliases_when_only_they_have_a_directory()
    {
        config(['account_manager.driver' => 'db']);
        $this->directory->enabled = false;
        $this->app->instance(AliasDirectory::class, $aliases = new FakeAliasDirectory);
        $domain = OrgDomain::factory()->create(['organization_id' => User::where('username', 'demo')->first()->organization->id, 'name' => 'corp.org', 'email_enabled' => true, 'email_status' => 'active']);
        $mailbox = Mailbox::create(['organization_id' => $domain->organization_id, 'org_domain_id' => $domain->id, 'local_part' => 'jane', 'address' => 'jane@corp.org', 'status' => Mailbox::ACTIVE]);
        $aliases->enabled = false;
        app(MailboxAliasService::class)->add($mailbox, 'sales', $domain);
        $aliases->enabled = true;
        $aliases->entries['jane@corp.org'] = [];

        $this->post('/admin/email/sync')->assertRedirect('/admin/email')->assertSessionHas('success');

        $this->assertSame(['sales@corp.org'], $aliases->entries['jane@corp.org']);
        $this->assertNull(session('sync_result'));
        $this->assertSame(1, session('alias_sync_result')['updated']);
    }
}
