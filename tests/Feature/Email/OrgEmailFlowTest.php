<?php

namespace Tests\Feature\Email;

use App\Enums\AccessType;
use App\Events\Users\UserEmailChanged;
use App\Mailbox;
use App\OrgDomain;
use App\Services\Email\MailboxService;
use App\Services\Email\RecoveryEmailDirectory;
use App\Services\Email\RecoveryEmailService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Domain;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Email\FakeMailServer;
use Tests\Support\Email\FakeRecoveryEmailDirectory;
use Tests\Support\TestSupports;
use Tests\TestCase;

class OrgEmailFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private OrgDomain $corp;

    protected function setUp(): void
    {
        parent::setUp();

        setupAccountManagerDriver('db');
        (new TestSupports)->seed();
        $this->user = User::where('username', 'demo')->firstOrFail();
        $this->user->forceFill(['access_type' => AccessType::STANDARD])->save();
        $this->actingAs($this->user);

        // What the gates need: email in the plan, and a domain that is the organization's
        $this->user->organization->plan->forceFill(['email_enabled' => true])->save();
        $this->corp = OrgDomain::factory()->create([
            'organization_id' => $this->user->organization->id,
            'name' => 'corp.org',
            'status' => 'active',
            'email_enabled' => true,
            'email_status' => 'active',
        ]);

        Event::fake();
        Notification::fake();
        Domain::shouldReceive('connect')->andReturn(new FakeMailServer);
        $this->app->instance(RecoveryEmailDirectory::class, new FakeRecoveryEmailDirectory);
    }

    private function email(): string
    {
        return $this->user->fresh()->email;
    }

    /**
     * An administrator gives the user jdoe@corp.org and the user accepts it, which is the only way
     * anyone gets an organization address.
     */
    private function switchEmail(array $accept = []): void
    {
        app(MailboxService::class)->offer(AccountManager::users()->find('demo'), $this->corp, 'jdoe');

        $this->post('/profile/org-email/accept', array_merge(['current_password' => 'demouser', 'acknowledge' => true], $accept));
    }

    public function test_the_profile_does_not_offer_to_get_an_organization_email_by_itself()
    {
        // Email is on for corp.org and the user could have an address there, but only an administrator gives one
        $this->get('/profile')->assertInertia(fn ($page) => $page
            ->where('org_email.mailbox', null)
            ->missing('org_email.can_get')
            ->missing('org_email.domains'));

        $this->post('/profile/org-email', ['domain' => $this->corp->id, 'local_part' => 'jdoe', 'current_password' => 'demouser', 'acknowledge' => true])
            ->assertNotFound();
        $this->assertDatabaseCount('mailboxes', 0);
        $this->assertSame('demo@example.com', $this->email());
    }

    public function test_the_page_afterwards_shows_the_address_and_how_long_it_can_be_undone()
    {
        $this->switchEmail();

        $this->get('/profile')->assertInertia(fn ($page) => $page
            ->where('org_email.mailbox.address', 'jdoe@corp.org')
            ->where('org_email.mailbox.status', 'active')
            ->where('org_email.mailbox.can_undo', true)
            ->where('org_email.mailbox.previous_mail', 'demo@example.com')
            ->where('profile.has_mailbox', true));
    }

    public function test_accepting_the_address_already_used_needs_no_acknowledgement()
    {
        $example = OrgDomain::factory()->create(['organization_id' => $this->user->organization->id, 'name' => 'example.com']);
        app(MailboxService::class)->offer(AccountManager::users()->find('demo'), $example, 'demo');

        $response = $this->post('/profile/org-email/accept', ['current_password' => 'demouser']);

        $response->assertSessionHasNoErrors();
        $this->assertSame('demo@example.com', $this->email());
        $this->assertDatabaseHas('mailboxes', ['address' => 'demo@example.com', 'status' => Mailbox::ACTIVE]);
    }

    public function test_a_wrong_password_changes_nothing()
    {
        app(MailboxService::class)->offer(AccountManager::users()->find('demo'), $this->corp, 'jdoe');

        $response = $this->post('/profile/org-email/accept', ['current_password' => 'wrong-password', 'acknowledge' => true]);

        $response->assertSessionHasErrors('current_password');
        $this->assertSame('demo@example.com', $this->email());
        $this->assertDatabaseHas('mailboxes', ['address' => 'jdoe@corp.org', 'status' => Mailbox::PENDING]);
    }

    public function test_a_hosted_current_email_asks_for_a_recovery_email_and_keeps_the_offer_waiting()
    {
        OrgDomain::factory()->create(['organization_id' => $this->user->organization->id, 'name' => 'example.com', 'email_status' => 'active']);
        app(MailboxService::class)->offer(AccountManager::users()->find('demo'), $this->corp, 'jdoe');
        $accept = ['current_password' => 'demouser', 'acknowledge' => true];

        $this->post('/profile/org-email/accept', $accept)->assertSessionHasErrors('recovery_email');

        $this->assertSame('demo@example.com', $this->email());
        $this->assertDatabaseHas('mailboxes', ['address' => 'jdoe@corp.org', 'status' => Mailbox::PENDING]);

        $this->post('/profile/org-email/accept', $accept + ['recovery_email' => 'me@gmail.com'])->assertSessionHasNoErrors();

        $this->assertSame('jdoe@corp.org', $this->email());
        $this->assertSame('me@gmail.com', app(RecoveryEmailService::class)->get('demo'));
    }

    public function test_an_offer_from_an_administrator_waits_until_the_user_accepts()
    {
        $mailboxes = app(MailboxService::class);
        $mailboxes->offer(AccountManager::users()->find('demo'), $this->corp, 'demo');

        $this->get('/profile')->assertInertia(fn ($page) => $page
            ->where('org_email.mailbox.status', 'pending')
            ->where('org_email.mailbox.address', 'demo@corp.org')
            ->where('org_email.mailbox.plan.changes_email', true));
        $this->assertSame('demo@example.com', $this->email());

        $this->post('/profile/org-email/accept', ['current_password' => 'demouser', 'acknowledge' => true])
            ->assertRedirect('/profile')
            ->assertSessionHas('success');

        $this->assertSame('demo@corp.org', $this->email());
    }

    public function test_accepting_an_offer_needs_the_warning_acknowledged()
    {
        app(MailboxService::class)->offer(AccountManager::users()->find('demo'), $this->corp, 'demo');

        $this->post('/profile/org-email/accept', ['current_password' => 'demouser'])->assertSessionHasErrors('acknowledge');

        $this->assertSame('demo@example.com', $this->email());
    }

    public function test_there_is_nothing_to_accept_without_an_offer()
    {
        $this->post('/profile/org-email/accept', ['current_password' => 'demouser', 'acknowledge' => true])
            ->assertRedirect('/profile')
            ->assertSessionHas('error');
    }

    public function test_the_switch_can_be_undone_from_the_profile_with_the_password()
    {
        $this->switchEmail();
        // The next request loads the user again, with the email they sign in with now
        $this->actingAs($this->user->fresh());

        $this->post('/profile/org-email/undo', ['current_password' => 'wrong-password'])->assertSessionHasErrors('current_password');
        $this->assertSame('jdoe@corp.org', $this->email());

        $this->post('/profile/org-email/undo', ['current_password' => 'demouser'])
            ->assertRedirect('/profile')
            ->assertSessionHas('success');

        $this->assertSame('demo@example.com', $this->email());
        $this->assertDatabaseHas('mailboxes', ['address' => 'jdoe@corp.org', 'status' => Mailbox::PENDING]);
    }

    public function test_the_users_list_shows_the_organization_address_and_flags_a_missing_recovery_email()
    {
        OrgDomain::factory()->create(['organization_id' => $this->user->organization->id, 'name' => 'example.com']);
        app(MailboxService::class)->offer(AccountManager::users()->find('demo'), OrgDomain::where('name', 'example.com')->first(), 'demo');
        $this->post('/profile/org-email/accept', ['current_password' => 'demouser']);

        $this->get('/users')->assertInertia(fn ($page) => $page
            ->where('users.0.id', 'demo')
            ->where('users.0.org_email', 'demo@example.com')
            ->where('users.0.recovery_missing', true));
    }

    public function test_an_administrator_offering_an_address_changes_nothing_for_the_user_yet()
    {
        $response = $this->get('/users/demo/create/accountemail/'.$this->corp->id);

        $response->assertRedirect('/users/demo');
        $response->assertSessionHas('success');
        $this->assertSame('demo@example.com', $this->email());
        $this->assertDatabaseHas('mailboxes', ['address' => 'demo@corp.org', 'status' => Mailbox::PENDING]);
    }

    public function test_an_administrator_offering_the_address_the_user_already_has_hosts_it_straight_away()
    {
        OrgDomain::factory()->create([
            'organization_id' => $this->user->organization->id,
            'name' => 'example.com',
            'status' => 'active',
            'email_enabled' => true,
            'email_status' => 'active',
        ]);

        $response = $this->get('/users/demo/create/accountemail/'.OrgDomain::where('name', 'example.com')->value('id'));

        $response->assertRedirect('/users/demo');
        $response->assertSessionHas('success');
        $this->assertSame('demo@example.com', $this->email());
        $this->assertDatabaseHas('mailboxes', ['address' => 'demo@example.com', 'status' => Mailbox::ACTIVE, 'swapped_at' => null]);
    }

    public function test_the_admin_user_page_shows_the_offer_and_the_organization_address()
    {
        $this->get('/users/demo/create/accountemail/'.$this->corp->id);

        $this->get('/users/demo/edit')->assertInertia(fn ($page) => $page
            ->where('user.offered_email', 'demo@corp.org')
            ->where('user.org_email', null)
            ->where('user.can.add_email_account', false));
    }

    public function test_an_administrator_can_offer_on_a_domain_being_activated_and_the_user_waits_for_it()
    {
        $soon = OrgDomain::factory()->create([
            'organization_id' => $this->user->organization->id,
            'name' => 'soon.org',
            'status' => 'active',
            'email_enabled' => false,
            'email_status' => 'activating',
        ]);

        $this->get('/users/demo/edit')->assertInertia(fn ($page) => $page
            ->where('email_domains.0.activating', false)
            ->where('email_domains.1.name', 'soon.org')
            ->where('email_domains.1.activating', true));

        $this->get('/users/demo/create/accountemail/'.$soon->id)->assertSessionHas('success');

        $this->get('/profile')->assertInertia(fn ($page) => $page
            ->where('org_email.mailbox.address', 'demo@soon.org')
            ->where('org_email.mailbox.domain_ready', false));

        $this->post('/profile/org-email/accept', ['current_password' => 'demouser', 'acknowledge' => true])
            ->assertSessionHas('error');
        $this->assertSame('demo@example.com', $this->email());
    }

    public function test_changing_the_email_someone_signs_in_with_tells_the_apps_what_it_was()
    {
        $this->post('/profile', [
            'first_name' => 'Demo',
            'last_name' => 'User',
            'personal_email' => 'new-address@example.org',
        ])->assertSessionHasNoErrors();

        Event::assertDispatched(UserEmailChanged::class, fn ($event) => $event->username === 'demo' && $event->old_email === 'demo@example.com' && $event->new_email === 'new-address@example.org');
    }

    public function test_saving_the_profile_without_changing_the_email_tells_no_one()
    {
        $this->post('/profile', [
            'first_name' => 'Demo',
            'last_name' => 'Renamed',
            'personal_email' => 'demo@example.com',
        ])->assertSessionHasNoErrors();

        Event::assertNotDispatched(UserEmailChanged::class);
    }

    public function test_an_administrator_changing_the_email_of_a_user_tells_the_apps_what_it_was()
    {
        $this->put('/users/demo', [
            'first_name' => 'Demo',
            'last_name' => 'User',
            'personal_email' => 'moved@example.org',
            'organization' => $this->user->organization->id,
        ])->assertSessionHasNoErrors();

        Event::assertDispatched(UserEmailChanged::class, fn ($event) => $event->username === 'demo' && $event->old_email === 'demo@example.com' && $event->new_email === 'moved@example.org');
    }

    public function test_an_administrator_cannot_edit_the_email_of_someone_who_has_an_organization_address()
    {
        $this->switchEmail();

        $response = $this->put('/users/demo', [
            'first_name' => 'Demo',
            'last_name' => 'User',
            'personal_email' => 'sneaky@gmail.com',
            'organization' => $this->user->organization->id,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('jdoe@corp.org', $this->email());
    }

    public function test_an_administrator_removing_the_address_puts_the_user_back()
    {
        $this->switchEmail();

        $this->get('/users/demo/remove/accountemail/jdoe@corp.org')->assertRedirect('/users/demo');

        $this->assertSame('demo@example.com', $this->email());
        $this->assertDatabaseCount('mailboxes', 0);
    }
}
