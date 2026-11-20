<?php

namespace Tests\Feature\Email;

use App\Actions\Email\AddEmailDomain;
use App\Enums\AccessType;
use App\Events\Mailboxes\MailboxActivated;
use App\Events\Users\UserUpdated;
use App\Mailbox;
use App\OrgDomain;
use App\Services\Email\DomainReadiness;
use App\Services\Email\MailboxService;
use App\Services\Email\RecoveryEmailDirectory;
use App\Services\Email\RecoveryEmailService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Domain;
use App\Task;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Inertia;
use Mockery;
use Tests\Support\Email\FakeMailServer;
use Tests\Support\Email\FakeRecoveryEmailDirectory;
use Tests\Support\TestSupports;
use Tests\TestCase;

class DomainReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private OrgDomain $domain;

    private FakeMailServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        setupAccountManagerDriver('db');
        (new TestSupports)->seed();
        $this->user = User::where('username', 'demo')->firstOrFail();
        $this->user->forceFill(['access_type' => AccessType::STANDARD])->save();
        $this->actingAs($this->user);
        // What the enable email gate needs
        $this->user->organization->plan->forceFill(['email_enabled' => true])->save();
        $this->app->instance(RecoveryEmailDirectory::class, new FakeRecoveryEmailDirectory);

        Event::fake([UserUpdated::class, MailboxActivated::class]);
        $this->server = new FakeMailServer;
        Domain::shouldReceive('connect')->andReturn($this->server);

        // The domain the user's email is on, moving here from another provider
        $this->domain = OrgDomain::factory()->create([
            'organization_id' => $this->user->organization->id,
            'name' => 'example.com',
            'status' => 'active',
            'email_enabled' => false,
            'email_status' => 'waiting_dns',
        ]);
    }

    private function names(array $people): array
    {
        return array_column($people, 'username');
    }

    private function goLive(): void
    {
        $this->domain->forceFill(['email_status' => 'active', 'email_enabled' => true])->save();
    }

    private function readiness(): array
    {
        return app(DomainReadiness::class)->for($this->domain->fresh());
    }

    public function test_a_user_with_an_email_on_the_domain_will_be_switched_over_and_is_asked_for_a_secondary_recovery_email()
    {
        $readiness = $this->readiness();

        $this->assertTrue($readiness['ready']);
        $this->assertGreaterThanOrEqual(1, $readiness['switching_count']);
        $this->assertContains('demo', $this->names($readiness['without_secondary']));
        $this->assertSame(0, $readiness['with_secondary_count']);
        $this->assertSame($readiness['switching_count'], $readiness['with_secondary_count'] + $readiness['without_secondary_count']);
    }

    public function test_a_user_with_a_secondary_recovery_email_is_counted_as_having_one()
    {
        app(RecoveryEmailService::class)->set('demo', 'backup@gmail.com');

        $readiness = $this->readiness();

        $this->assertSame(1, $readiness['with_secondary_count']);
        $this->assertNotContains('demo', $this->names($readiness['without_secondary']));
    }

    public function test_the_address_a_user_was_created_with_is_not_a_secondary_recovery_email()
    {
        // Creating a user keeps the address they were created with, which is their login email
        app(RecoveryEmailService::class)->set('demo', 'demo@example.com');

        $readiness = $this->readiness();

        $this->assertSame(0, $readiness['with_secondary_count']);
        $this->assertContains('demo', $this->names($readiness['without_secondary']));
    }

    public function test_a_secondary_recovery_email_on_the_domain_itself_does_not_count_even_before_email_is_enabled()
    {
        // Not hosted yet, so the address looks usable, but it moves with the mail
        $this->domain->forceFill(['email_status' => 'disabled'])->save();
        app(RecoveryEmailService::class)->set('demo', 'backup@example.com');

        $readiness = $this->readiness();

        $this->assertSame(0, $readiness['with_secondary_count']);
        $this->assertContains('demo', $this->names($readiness['without_secondary']));
        $this->assertSame(['demo'], $this->names($readiness['recovery_on_domain']));
    }

    public function test_a_user_who_cannot_have_a_mailbox_makes_the_domain_not_ready_and_is_not_switched()
    {
        $this->user->forceFill(['access_type' => AccessType::BASIC])->save();
        $this->goLive();

        $readiness = $this->readiness();
        $result = app(DomainReadiness::class)->switchOver($this->domain->fresh());

        $this->assertFalse($readiness['ready']);
        $this->assertSame('demo', $readiness['cannot_switch'][0]['username']);
        $this->assertSame('not_standard', $readiness['cannot_switch'][0]['reason']);
        $this->assertSame(0, $result['switched']);
        $this->assertDatabaseMissing('mailboxes', ['username' => 'demo']);
    }

    public function test_a_user_who_was_offered_a_different_address_has_to_accept_it_themselves()
    {
        $other = OrgDomain::factory()->create([
            'organization_id' => $this->user->organization->id,
            'name' => 'corp.org',
            'status' => 'active',
            'email_enabled' => true,
            'email_status' => 'active',
        ]);
        app(MailboxService::class)->offer(AccountManager::users()->find('demo'), $other, 'jdoe');
        $this->goLive();

        $readiness = $this->readiness();
        $result = app(DomainReadiness::class)->switchOver($this->domain->fresh());

        $this->assertSame('other_offer', $readiness['cannot_switch'][0]['reason']);
        $this->assertSame(0, $result['switched']);
        $this->assertDatabaseHas('mailboxes', ['address' => 'jdoe@corp.org', 'status' => Mailbox::PENDING]);
    }

    public function test_switching_over_gives_the_address_they_already_have_and_activates_it_without_changing_anything()
    {
        $this->goLive();

        $result = app(DomainReadiness::class)->switchOver($this->domain->fresh());

        $this->assertGreaterThanOrEqual(1, $result['switched']);
        $this->assertSame([], $result['failed']);
        $this->assertDatabaseHas('mailboxes', [
            'username' => 'demo',
            'address' => 'demo@example.com',
            'status' => Mailbox::ACTIVE,
            'org_domain_id' => $this->domain->id,
            'swapped_at' => null,
        ]);
        $this->assertSame('demo@example.com', $this->user->fresh()->email);
        $this->assertContains('demo', $this->server->created);
        Event::assertDispatched(MailboxActivated::class);

        $readiness = $this->readiness();

        $this->assertSame(0, $readiness['switching_count']);
        $this->assertGreaterThanOrEqual(1, $readiness['already_count']);
    }

    public function test_switching_over_accepts_an_offer_already_made_and_does_not_do_it_twice()
    {
        $mailbox = app(MailboxService::class)->offer(AccountManager::users()->find('demo'), $this->domain, 'demo');
        $this->goLive();

        app(DomainReadiness::class)->switchOver($this->domain->fresh());
        $count = Mailbox::count();
        $again = app(DomainReadiness::class)->switchOver($this->domain->fresh());

        $this->assertSame(Mailbox::ACTIVE, $mailbox->fresh()->status);
        $this->assertSame(0, $again['switched']);
        $this->assertSame($count, Mailbox::count());
    }

    public function test_nothing_is_switched_while_the_domain_is_still_being_set_up()
    {
        $result = app(DomainReadiness::class)->switchOver($this->domain);

        $this->assertSame(0, $result['switched']);
        $this->assertContains('demo', $result['failed']);
        $this->assertDatabaseHas('mailboxes', ['username' => 'demo', 'status' => Mailbox::PENDING]);
        $this->assertSame([], $this->server->created);
    }

    public function test_when_the_mail_server_refuses_the_offer_stays_for_the_user_to_accept_later()
    {
        $this->goLive();
        $this->server->failing = true;

        $result = app(DomainReadiness::class)->switchOver($this->domain->fresh());

        $this->assertSame(0, $result['switched']);
        $this->assertContains('demo', $result['failed']);
        $this->assertDatabaseHas('mailboxes', ['username' => 'demo', 'address' => 'demo@example.com', 'status' => Mailbox::PENDING]);
        $this->assertSame('demo@example.com', $this->user->fresh()->email);
    }

    public function test_an_address_is_only_accepted_for_the_user_when_it_changes_nothing_for_them()
    {
        $this->goLive();
        $other = OrgDomain::factory()->create([
            'organization_id' => $this->user->organization->id,
            'name' => 'corp.org',
            'status' => 'active',
            'email_enabled' => true,
            'email_status' => 'active',
        ]);
        $user = AccountManager::users()->find('demo');
        $service = app(MailboxService::class);
        $different = $service->offer($user, $other, 'jdoe');

        $this->assertFalse($service->acceptIfUnchanged($user, $different));
        $this->assertSame(Mailbox::PENDING, $different->fresh()->status);
        $this->assertSame('demo@example.com', $this->user->fresh()->email);
    }

    public function test_the_domain_page_reports_who_is_switching_while_it_is_being_set_up_and_the_enable_dialog_asks_for_the_same_before()
    {
        $this->get('/settings/domains/example.com')->assertInertia(fn ($page) => $page
            ->where('domain.email.readiness.ready', true)
            ->where('domain.email.readiness.switching_count', 1)
            ->missing('enable_preview'));

        $this->domain->forceFill(['email_status' => 'disabled'])->save();
        app(RecoveryEmailService::class)->set('demo', 'backup@gmail.com');

        $this->get('/settings/domains/example.com')->assertInertia(fn ($page) => $page
            ->where('domain.email.readiness', null)
            ->missing('enable_preview'));

        $this->get('/settings/domains/example.com', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Organization/Settings/WebDomains/WebDomainsSettings', 'X-Inertia-Partial-Data' => 'enable_preview', 'X-Inertia-Version' => (string) Inertia::getVersion()])
            ->assertJsonPath('props.enable_preview.switching_count', 1)
            ->assertJsonPath('props.enable_preview.with_secondary_count', 1);
    }

    public function test_enabling_a_domain_switches_everyone_over_when_it_goes_live()
    {
        $this->domain->forceFill(['email_status' => 'activating', 'dkim_public_key' => 'key', 'type' => 'connection'])->save();

        $task = Mockery::mock(Task::class)->makePartial();
        $task->setRelation('organization', $this->user->organization);
        $task->custom_values = ['domain_name' => 'example.com'];
        $task->shouldReceive('complete')->once();
        $task->shouldReceive('groupNotified')->once();

        AddEmailDomain::complete($task);

        $this->assertSame('active', $this->domain->fresh()->email_status);
        $this->assertDatabaseHas('mailboxes', ['username' => 'demo', 'address' => 'demo@example.com', 'status' => Mailbox::ACTIVE]);
        $this->assertSame('demo@example.com', $this->user->fresh()->email);
    }
}
