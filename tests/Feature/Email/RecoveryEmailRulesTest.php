<?php

namespace Tests\Feature\Email;

use App\Events\Users\UserCreated;
use App\Ldap\Models\User as LdapUser;
use App\Listeners\StoreRecoveryEmail;
use App\Mailbox;
use App\OrgDomain;
use App\Services\Email\RecoveryEmailDirectory;
use App\Services\Email\RecoveryEmailService;
use App\Support\AccountManager\UserManager;
use App\User;
use App\UserRecoveryEmail;
use Illuminate\Auth\Notifications\ResetPassword as LaravelResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification as BaseNotification;
use Mockery;
use Tests\Support\Email\FakeRecoveryEmailDirectory;
use Tests\Support\TestSupports;
use Tests\TestCase;

class RecoveryEmailRulesTest extends TestCase
{
    use RefreshDatabase;

    private RecoveryEmailService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        (new TestSupports)->seed();
        $this->user = User::where('username', 'demo')->firstOrFail();
        $this->app->instance(RecoveryEmailDirectory::class, new FakeRecoveryEmailDirectory);
        $this->service = $this->app->make(RecoveryEmailService::class);
    }

    private function otherNotification(): BaseNotification
    {
        return new class extends BaseNotification {};
    }

    private function host(string $domain, string $status = 'active'): void
    {
        OrgDomain::factory()->create([
            'organization_id' => $this->user->organization->id,
            'name' => $domain,
            'email_status' => $status,
        ]);
    }

    private function mailbox(string $address, string $status = Mailbox::ACTIVE): Mailbox
    {
        return Mailbox::create([
            'organization_id' => $this->user->organization->id,
            'local_part' => explode('@', $address)[0],
            'address' => $address,
            'username' => 'demo',
            'status' => $status,
        ]);
    }

    public function test_a_recovery_email_elsewhere_is_usable()
    {
        $this->service->set('demo', 'backup@gmail.com');

        $this->assertSame('backup@gmail.com', $this->service->usable('demo', 'demo@example.org'));
    }

    public function test_no_recovery_email_is_not_usable()
    {
        $this->assertNull($this->service->usable('demo', 'demo@example.org'));
    }

    public function test_a_recovery_email_equal_to_mail_is_not_usable_whatever_its_case()
    {
        $this->service->set('demo', 'Demo@Example.org');

        $this->assertNull($this->service->usable('demo', 'demo@example.org'));
    }

    public function test_a_recovery_email_on_a_hosted_domain_is_not_usable()
    {
        $this->host('example.org');
        $this->service->set('demo', 'other@EXAMPLE.org');

        $this->assertNull($this->service->usable('demo', 'demo@example.org'));
    }

    public function test_a_domain_still_being_activated_counts_as_hosted_but_a_disabled_one_does_not()
    {
        $this->host('activating.org', 'activating');
        $this->host('disabled.org', 'disabled');

        $this->service->set('demo', 'a@activating.org');
        $this->assertNull($this->service->usable('demo', 'demo@example.org'));

        $this->service->set('demo', 'a@disabled.org');
        $this->assertSame('a@disabled.org', $this->service->usable('demo', 'demo@example.org'));
    }

    public function test_resets_go_to_a_usable_recovery_email_and_otherwise_to_mail()
    {
        $this->assertSame('demo@example.org', $this->service->destination('demo', 'demo@example.org'));

        $this->service->set('demo', 'backup@gmail.com');
        $this->assertSame('backup@gmail.com', $this->service->destination('demo', 'demo@example.org'));

        $this->service->set('demo', 'demo@example.org');
        $this->assertSame('demo@example.org', $this->service->destination('demo', 'demo@example.org'));
    }

    public function test_the_database_user_gets_resets_and_invites_at_the_recovery_email_and_everything_else_at_mail()
    {
        $this->service->set('demo', 'backup@gmail.com');

        $this->assertSame('backup@gmail.com', $this->user->routeNotificationForMail(new LaravelResetPassword('token')));
        $this->assertSame($this->user->email, $this->user->routeNotificationForMail($this->otherNotification()));
    }

    public function test_the_ldap_user_gets_resets_at_the_recovery_email_and_everything_else_at_mail()
    {
        $this->service->set('jane', 'backup@gmail.com');
        $entry = new LdapUser;
        $entry->setAttribute('cn', 'jane');
        $entry->setAttribute('mail', 'jane@example.org');
        $entry->setAttribute('displayName', 'Jane');

        $this->assertSame(['backup@gmail.com' => 'Jane'], $entry->routeNotificationForMail(new LaravelResetPassword('token')));
        $this->assertSame(['jane@example.org' => 'Jane'], $entry->routeNotificationForMail($this->otherNotification()));
    }

    public function test_the_address_a_user_is_created_with_is_kept_as_their_recovery_email()
    {
        $user = Mockery::mock(UserManager::class);
        $user->shouldReceive('organization')->andReturnNull();
        $user->shouldReceive('attribute')->with('username')->andReturn('newbie');
        $user->shouldReceive('attribute')->with('email')->andReturn('newbie@gmail.com');

        $this->app->make(StoreRecoveryEmail::class)->handle(new UserCreated($user));

        $this->assertSame('newbie@gmail.com', $this->service->get('newbie'));
    }

    public function test_creating_a_user_does_not_overwrite_a_recovery_email_that_is_already_there()
    {
        $this->service->set('newbie', 'kept@gmail.com');
        $user = Mockery::mock(UserManager::class);
        $user->shouldReceive('organization')->andReturnNull();
        $user->shouldReceive('attribute')->with('username')->andReturn('newbie');
        $user->shouldReceive('attribute')->with('email')->andReturn('newbie@gmail.com');

        $this->app->make(StoreRecoveryEmail::class)->handle(new UserCreated($user));

        $this->assertSame('kept@gmail.com', $this->service->get('newbie'));
        $this->assertSame(1, UserRecoveryEmail::where('username', 'newbie')->count());
    }
}
