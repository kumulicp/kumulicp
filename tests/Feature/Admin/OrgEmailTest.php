<?php

namespace Tests\Feature\Admin;

use App\Services\Email\RecoveryEmailDirectory;
use App\Support\Facades\Settings;
use App\User;
use App\UserRecoveryEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->get('/admin/org/email')->assertInertia(fn ($page) => $page
            ->component('Admin/Email/EmailSync')
            ->where('ldap_enabled', false)
            ->where('aliases.enabled', true)
            ->where('recovery.sync_enabled', false)
            ->where('recovery.count', 1));
    }

    public function test_with_ldap_aliases_stay_disabled_until_an_attribute_is_set()
    {
        config(['account_manager.driver' => 'ldap']);

        $this->get('/admin/org/email')->assertInertia(fn ($page) => $page
            ->where('ldap_enabled', true)
            ->where('aliases.enabled', false)
            ->where('aliases.attribute', null));

        Settings::update('ldap_aliases', 'proxyAddresses');

        $this->get('/admin/org/email')->assertInertia(fn ($page) => $page
            ->where('aliases.enabled', true)
            ->where('aliases.attribute', 'proxyAddresses'));
    }

    public function test_the_sync_is_offered_once_ldap_has_a_recovery_email_attribute()
    {
        config(['account_manager.driver' => 'ldap']);
        Settings::update('ldap_recovery_email', 'recoveryMail');

        $this->get('/admin/org/email')->assertInertia(fn ($page) => $page
            ->where('recovery.attribute', 'recoveryMail')
            ->where('recovery.sync_enabled', true));
    }

    public function test_sync_copies_the_database_to_the_directory()
    {
        config(['account_manager.driver' => 'ldap']);
        Settings::update('ldap_recovery_email', 'recoveryMail');
        UserRecoveryEmail::create(['username' => 'jane', 'email' => 'jane@example.com']);
        $this->directory->entries = ['jane' => 'stale@example.com'];

        $response = $this->post('/admin/org/email/sync');

        $response->assertRedirect('/admin/org/email');
        $response->assertSessionHas('success');
        $this->assertSame('jane@example.com', $this->directory->entries['jane']);
        $this->assertSame(1, session('sync_result')['updated']);
    }

    public function test_sync_shows_what_it_found_on_the_page_it_returns_to()
    {
        config(['account_manager.driver' => 'ldap']);
        Settings::update('ldap_recovery_email', 'recoveryMail');
        UserRecoveryEmail::create(['username' => 'gone', 'email' => 'gone@example.com']);

        $this->post('/admin/org/email/sync')->assertSessionHas('error');

        $this->get('/admin/org/email')->assertInertia(fn ($page) => $page
            ->where('sync_result.missing', ['gone']));
    }

    public function test_sync_refuses_when_there_is_no_directory_to_sync_with()
    {
        config(['account_manager.driver' => 'db']);
        $this->directory->enabled = false;
        UserRecoveryEmail::create(['username' => 'jane', 'email' => 'jane@example.com']);
        $this->directory->entries = ['jane' => null];

        $response = $this->post('/admin/org/email/sync');

        $response->assertRedirect('/admin/org/email');
        $response->assertSessionHas('error');
        $this->assertSame([], $this->directory->writes);
    }
}
