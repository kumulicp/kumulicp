<?php

namespace Tests\Feature\Email;

use App\Enums\AccessType;
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'domain' => $this->corp->id,
            'local_part' => 'jdoe',
            'current_password' => 'demouser',
            'acknowledge' => true,
        ], $overrides);
    }

    public function test_the_profile_offers_an_organization_email_to_someone_who_can_have_one()
    {
        $this->get('/profile')->assertInertia(fn ($page) => $page
            ->where('org_email.can_get', true)
            ->where('org_email.mailbox', null)
            ->where('org_email.default_name', 'demo')
            ->where('org_email.domains.0.name', 'corp.org')
            ->where('org_email.current_hosted', false));
    }

    public function test_someone_who_may_not_have_one_is_not_offered_it()
    {
        $this->user->forceFill(['access_type' => AccessType::BASIC])->save();

        $this->get('/profile')->assertInertia(fn ($page) => $page->where('org_email.can_get', false));
    }

    public function test_choosing_an_address_switches_the_email_in_one_step()
    {
        $response = $this->post('/profile/org-email', $this->payload());

        $response->assertRedirect('/profile');
        $response->assertSessionHas('success');
        $this->assertSame('jdoe@corp.org', $this->email());
        $this->assertSame('demo@example.com', app(RecoveryEmailService::class)->get('demo'));
        $this->assertDatabaseHas('mailboxes', ['address' => 'jdoe@corp.org', 'status' => Mailbox::ACTIVE, 'username' => 'demo']);
    }

    public function test_the_page_afterwards_shows_the_address_and_how_long_it_can_be_undone()
    {
        $this->post('/profile/org-email', $this->payload());

        $this->get('/profile')->assertInertia(fn ($page) => $page
            ->where('org_email.mailbox.address', 'jdoe@corp.org')
            ->where('org_email.mailbox.status', 'active')
            ->where('org_email.mailbox.can_undo', true)
            ->where('org_email.mailbox.previous_mail', 'demo@example.com')
            ->where('org_email.can_get', false)
            ->where('profile.has_mailbox', true));
    }

    public function test_the_warning_has_to_be_acknowledged_when_the_email_changes()
    {
        $response = $this->post('/profile/org-email', $this->payload(['acknowledge' => false]));

        $response->assertSessionHasErrors('acknowledge');
        $this->assertSame('demo@example.com', $this->email());
        $this->assertDatabaseCount('mailboxes', 0);
    }

    public function test_hosting_the_address_already_used_needs_no_acknowledgement()
    {
        OrgDomain::factory()->create(['organization_id' => $this->user->organization->id, 'name' => 'example.com']);

        $response = $this->post('/profile/org-email', $this->payload([
            'domain' => OrgDomain::where('name', 'example.com')->value('id'),
            'local_part' => 'demo',
            'acknowledge' => false,
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame('demo@example.com', $this->email());
        $this->assertDatabaseHas('mailboxes', ['address' => 'demo@example.com', 'status' => Mailbox::ACTIVE]);
    }

    public function test_a_wrong_password_changes_nothing()
    {
        $response = $this->post('/profile/org-email', $this->payload(['current_password' => 'wrong-password']));

        $response->assertSessionHasErrors('current_password');
        $this->assertSame('demo@example.com', $this->email());
        $this->assertDatabaseCount('mailboxes', 0);
    }

    public function test_a_name_that_is_not_allowed_or_is_taken_is_refused()
    {
        Mailbox::create(['organization_id' => 1, 'local_part' => 'jdoe', 'address' => 'jdoe@corp.org', 'username' => 'someone']);

        $this->post('/profile/org-email', $this->payload())->assertSessionHasErrors('local_part');
        $this->post('/profile/org-email', $this->payload(['local_part' => 'J Doe']))->assertSessionHasErrors('local_part');
        $this->assertSame('demo@example.com', $this->email());
    }

    public function test_a_domain_of_another_organization_is_refused()
    {
        $other = OrgDomain::factory()->create(['organization_id' => 999999, 'name' => 'foreign.org']);

        $this->post('/profile/org-email', $this->payload(['domain' => $other->id]))->assertSessionHasErrors('domain');
        $this->assertDatabaseCount('mailboxes', 0);
    }

    public function test_a_hosted_current_email_asks_for_a_recovery_email_and_leaves_nothing_behind()
    {
        OrgDomain::factory()->create(['organization_id' => $this->user->organization->id, 'name' => 'example.com', 'email_status' => 'active']);

        $this->post('/profile/org-email', $this->payload())->assertSessionHasErrors('recovery_email');

        $this->assertSame('demo@example.com', $this->email());
        $this->assertDatabaseCount('mailboxes', 0);

        $this->post('/profile/org-email', $this->payload(['recovery_email' => 'me@gmail.com']))->assertSessionHasNoErrors();

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
        $this->post('/profile/org-email', $this->payload());

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
        $this->post('/profile/org-email', $this->payload([
            'domain' => OrgDomain::where('name', 'example.com')->value('id'),
            'local_part' => 'demo',
            'acknowledge' => false,
        ]));

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

    public function test_the_admin_user_page_shows_the_offer_and_the_organization_address()
    {
        $this->get('/users/demo/create/accountemail/'.$this->corp->id);

        $this->get('/users/demo/edit')->assertInertia(fn ($page) => $page
            ->where('user.offered_email', 'demo@corp.org')
            ->where('user.org_email', null)
            ->where('user.can.add_email_account', false));
    }

    public function test_an_administrator_cannot_edit_the_email_of_someone_who_has_an_organization_address()
    {
        $this->post('/profile/org-email', $this->payload());

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
        $this->post('/profile/org-email', $this->payload());

        $this->get('/users/demo/remove/accountemail/jdoe@corp.org')->assertRedirect('/users/demo');

        $this->assertSame('demo@example.com', $this->email());
        $this->assertDatabaseCount('mailboxes', 0);
    }
}
