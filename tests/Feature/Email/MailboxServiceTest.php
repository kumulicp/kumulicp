<?php

namespace Tests\Feature\Email;

use App\Enums\AccessType;
use App\Events\Mailboxes\MailboxActivated;
use App\Events\Mailboxes\MailboxDeactivated;
use App\Events\Users\UserUpdated;
use App\Mailbox;
use App\Notifications\PrimaryEmailChanged;
use App\OrgDomain;
use App\Services\Email\MailboxException;
use App\Services\Email\MailboxService;
use App\Services\Email\RecoveryEmailDirectory;
use App\Services\Email\RecoveryEmailRequired;
use App\Services\Email\RecoveryEmailService;
use App\Support\AccountManager\UserManager;
use App\Support\Email\UserEmails;
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

class MailboxServiceTest extends TestCase
{
    use RefreshDatabase;

    private MailboxService $service;

    private RecoveryEmailService $recovery;

    private FakeMailServer $server;

    private UserManager $manager;

    private OrgDomain $corp;

    protected function setUp(): void
    {
        parent::setUp();

        setupAccountManagerDriver('db');
        (new TestSupports)->seed();
        $this->actingAs(User::where('username', 'demo')->firstOrFail());

        Event::fake([UserUpdated::class, MailboxActivated::class, MailboxDeactivated::class]);
        Notification::fake();

        $this->server = new FakeMailServer;
        Domain::shouldReceive('connect')->andReturn($this->server);
        $this->app->instance(RecoveryEmailDirectory::class, new FakeRecoveryEmailDirectory);

        $this->service = $this->app->make(MailboxService::class);
        $this->recovery = $this->app->make(RecoveryEmailService::class);
        $this->manager = AccountManager::users()->find('demo');
        $this->corp = $this->domain('corp.org', 'active');
    }

    private function domain(string $name, string $email_status = 'disabled'): OrgDomain
    {
        return OrgDomain::factory()->create([
            'organization_id' => User::where('username', 'demo')->first()->organization->id,
            'name' => $name,
            'email_status' => $email_status,
        ]);
    }

    private function email(): string
    {
        return User::where('username', 'demo')->first()->email;
    }

    // --- offering

    public function test_an_offer_is_a_pending_mailbox_and_changes_nothing_about_the_user()
    {
        $mailbox = $this->service->offer($this->manager, $this->corp, 'JDoe', 'Demo User');

        $this->assertSame('jdoe@corp.org', $mailbox->address);
        $this->assertSame('jdoe', $mailbox->local_part);
        $this->assertSame(Mailbox::PENDING, $mailbox->status);
        $this->assertSame('demo', $mailbox->username);
        $this->assertSame('demo@example.com', $this->email());
    }

    public function test_a_user_gets_one_organization_address()
    {
        $this->service->offer($this->manager, $this->corp, 'jdoe');

        $this->expectException(MailboxException::class);
        $this->expectExceptionMessage(__('organization.email.mailbox.has_one'));

        $this->service->offer($this->manager, $this->corp, 'other');
    }

    public function test_an_address_someone_else_has_cannot_be_offered()
    {
        Mailbox::create(['organization_id' => 1, 'local_part' => 'taken', 'address' => 'taken@corp.org', 'username' => 'someone']);

        $this->expectException(MailboxException::class);
        $this->expectExceptionMessage(__('organization.email.mailbox.taken', ['email' => 'taken@corp.org']));

        $this->service->offer($this->manager, $this->corp, 'taken');
    }

    // --- what accepting will do

    public function test_accepting_the_address_the_user_already_has_changes_nothing()
    {
        $example = $this->domain('example.com');
        $mailbox = $this->service->offer($this->manager, $example, 'demo');

        $this->assertSame(['changes_email' => false, 'current' => 'demo@example.com', 'recovery' => 'keep'], $this->service->plan($this->manager, $mailbox));
    }

    public function test_a_different_address_moves_the_current_email_to_be_the_recovery_email()
    {
        $mailbox = $this->service->offer($this->manager, $this->corp, 'jdoe');

        $this->assertSame(['changes_email' => true, 'current' => 'demo@example.com', 'recovery' => 'move'], $this->service->plan($this->manager, $mailbox));
    }

    public function test_a_recovery_email_that_works_is_kept()
    {
        $this->recovery->set('demo', 'backup@gmail.com');
        $mailbox = $this->service->offer($this->manager, $this->corp, 'jdoe');

        $this->assertSame('keep', $this->service->plan($this->manager, $mailbox)['recovery']);
    }

    public function test_a_current_email_on_a_hosted_domain_cannot_become_the_recovery_email()
    {
        $this->domain('example.com', 'active');
        $mailbox = $this->service->offer($this->manager, $this->corp, 'jdoe');

        $this->assertSame('required', $this->service->plan($this->manager, $mailbox)['recovery']);
    }

    // --- accepting

    public function test_accepting_a_different_address_switches_the_email_and_keeps_the_old_one_as_recovery()
    {
        $mailbox = $this->service->offer($this->manager, $this->corp, 'jdoe');

        $this->service->accept($this->manager, $mailbox);

        $mailbox->refresh();
        $this->assertSame('jdoe@corp.org', $this->email());
        $this->assertSame('demo@example.com', $this->recovery->get('demo'));
        $this->assertSame(Mailbox::ACTIVE, $mailbox->status);
        $this->assertSame('demo@example.com', $mailbox->previous_mail);
        $this->assertNotNull($mailbox->swapped_at);
        $this->assertSame(['demo'], $this->server->created);
        Event::assertDispatched(MailboxActivated::class);
        Event::assertDispatched(UserUpdated::class);
        Notification::assertSentOnDemand(PrimaryEmailChanged::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'demo@example.com');
    }

    public function test_the_recovery_email_that_was_moved_is_trusted_but_the_panel_copy_of_the_email_follows()
    {
        $mailbox = $this->service->offer($this->manager, $this->corp, 'jdoe');

        $this->service->accept($this->manager, $mailbox);

        $this->assertNotNull(\App\UserRecoveryEmail::where('username', 'demo')->first()->verified_at);
        $this->assertSame(
            ['personal_email' => 'demo@example.com', 'org_email' => 'jdoe@corp.org', 'recovery_missing' => false],
            UserEmails::for(AccountManager::users()->find('demo')),
        );
    }

    public function test_accepting_the_address_the_user_already_has_only_records_the_mailbox()
    {
        $mailbox = $this->service->offer($this->manager, $this->domain('example.com'), 'demo');

        $this->service->accept($this->manager, $mailbox);

        $mailbox->refresh();
        $this->assertSame('demo@example.com', $this->email());
        $this->assertSame(Mailbox::ACTIVE, $mailbox->status);
        $this->assertNull($mailbox->swapped_at);
        $this->assertNull($mailbox->previous_mail);
        $this->assertNull($this->recovery->get('demo'));
        $this->assertSame(['demo'], $this->server->created);
        Notification::assertNothingSent();
    }

    public function test_a_recovery_email_that_works_stays_and_the_current_email_is_dropped()
    {
        $this->recovery->set('demo', 'backup@gmail.com');
        $mailbox = $this->service->offer($this->manager, $this->corp, 'jdoe');

        $this->service->accept($this->manager, $mailbox);

        $this->assertSame('jdoe@corp.org', $this->email());
        $this->assertSame('backup@gmail.com', $this->recovery->get('demo'));
        $this->assertSame('backup@gmail.com', $mailbox->fresh()->previous_recovery_email);
    }

    public function test_a_hosted_current_email_needs_a_recovery_email_to_be_given_first()
    {
        $this->domain('example.com', 'active');
        $mailbox = $this->service->offer($this->manager, $this->corp, 'jdoe');

        try {
            $this->service->accept($this->manager, $mailbox);
            $this->fail('It should have asked for a recovery email');
        } catch (RecoveryEmailRequired) {
            // expected
        }

        $this->assertSame('demo@example.com', $this->email());
        $this->assertSame(Mailbox::PENDING, $mailbox->fresh()->status);
    }

    public function test_a_recovery_email_on_a_hosted_domain_or_equal_to_the_new_address_is_refused()
    {
        $this->domain('example.com', 'active');
        $mailbox = $this->service->offer($this->manager, $this->corp, 'jdoe');

        foreach (['someone@corp.org', 'jdoe@corp.org', 'demo@example.com', ''] as $bad) {
            try {
                $this->service->accept($this->manager, $mailbox, $bad);
                $this->fail("$bad should have been refused");
            } catch (RecoveryEmailRequired) {
                // expected
            }
        }
    }

    public function test_a_recovery_email_given_at_another_provider_is_used()
    {
        $this->domain('example.com', 'active');
        $mailbox = $this->service->offer($this->manager, $this->corp, 'jdoe');

        $this->service->accept($this->manager, $mailbox, 'Me@Gmail.com');

        $this->assertSame('jdoe@corp.org', $this->email());
        $this->assertSame('me@gmail.com', $this->recovery->get('demo'));
    }

    public function test_a_mailbox_that_is_not_waiting_cannot_be_accepted_again_or_by_someone_else()
    {
        $mailbox = $this->service->offer($this->manager, $this->corp, 'jdoe');
        $this->service->accept($this->manager, $mailbox);

        $this->expectException(MailboxException::class);

        $this->service->accept($this->manager, $mailbox->fresh());
    }

    public function test_a_failure_part_way_puts_everything_back_and_leaves_the_offer_waiting()
    {
        $this->recovery->set('demo', 'demo@example.com');
        $mailbox = $this->service->offer($this->manager, $this->corp, 'jdoe');
        $this->server->failing = true;

        try {
            $this->service->accept($this->manager, $mailbox);
            $this->fail('The mail server failure should have surfaced');
        } catch (MailboxException $e) {
            $this->assertSame(__('organization.email.mailbox.failed'), $e->getMessage());
        }

        $this->assertSame('demo@example.com', $this->email());
        $this->assertSame('demo@example.com', $this->recovery->get('demo'));
        $this->assertSame(Mailbox::PENDING, $mailbox->fresh()->status);
        Event::assertNotDispatched(MailboxActivated::class);
        Notification::assertNothingSent();
    }

    // --- undoing

    public function test_the_switch_can_be_undone_for_a_while()
    {
        $mailbox = $this->service->offer($this->manager, $this->corp, 'jdoe');
        $this->service->accept($this->manager, $mailbox);

        $this->service->undo(AccountManager::users()->find('demo'), $mailbox->fresh());

        $mailbox->refresh();
        $this->assertSame('demo@example.com', $this->email());
        $this->assertNull($this->recovery->get('demo'));
        $this->assertSame(Mailbox::PENDING, $mailbox->status);
        $this->assertNull($mailbox->previous_mail);
        $this->assertSame(['demo'], $this->server->deleted);
        Event::assertDispatched(MailboxDeactivated::class);
    }

    public function test_the_switch_cannot_be_undone_after_the_window()
    {
        $mailbox = $this->service->offer($this->manager, $this->corp, 'jdoe');
        $this->service->accept($this->manager, $mailbox);
        $this->travel((int) config('email.undo_days') + 1)->days();

        $this->assertFalse($mailbox->fresh()->canBeUndone());
        $this->expectException(MailboxException::class);

        $this->service->undo($this->manager, $mailbox->fresh());
    }

    public function test_hosting_the_address_a_user_already_had_has_nothing_to_undo()
    {
        $mailbox = $this->service->offer($this->manager, $this->domain('example.com'), 'demo');
        $this->service->accept($this->manager, $mailbox);

        $this->assertFalse($mailbox->fresh()->canBeUndone());
    }

    // --- removing

    public function test_removing_a_switched_mailbox_puts_the_user_back_on_their_recovery_email()
    {
        $mailbox = $this->service->offer($this->manager, $this->corp, 'jdoe');
        $this->service->accept($this->manager, $mailbox);

        $this->service->remove(AccountManager::users()->find('demo'), $mailbox->fresh());

        $this->assertSame('demo@example.com', $this->email());
        $this->assertNull($this->recovery->get('demo'));
        $this->assertDatabaseMissing('mailboxes', ['id' => $mailbox->id]);
    }

    public function test_removing_the_mailbox_of_someone_with_no_usable_recovery_email_leaves_their_email()
    {
        $mailbox = $this->service->offer($this->manager, $this->domain('example.com'), 'demo');
        $this->service->accept($this->manager, $mailbox);

        $this->service->remove($this->manager, $mailbox->fresh());

        $this->assertSame('demo@example.com', $this->email());
        $this->assertDatabaseMissing('mailboxes', ['id' => $mailbox->id]);
    }

    public function test_removing_an_offer_only_deletes_it()
    {
        $mailbox = $this->service->offer($this->manager, $this->corp, 'jdoe');

        $this->service->remove($this->manager, $mailbox);

        $this->assertDatabaseMissing('mailboxes', ['id' => $mailbox->id]);
        $this->assertSame('demo@example.com', $this->email());
        $this->assertSame([], $this->server->deleted);
    }

    public function test_a_users_mailboxes_go_when_the_user_does()
    {
        $this->service->offer($this->manager, $this->corp, 'jdoe');

        $this->manager->delete();

        $this->assertDatabaseCount('mailboxes', 0);
    }

    public function test_users_with_an_organization_address_and_no_usable_recovery_email_are_flagged()
    {
        $this->assertFalse(UserEmails::for($this->manager)['recovery_missing'], 'no organization address, nothing to flag');

        $mailbox = $this->service->offer($this->manager, $this->domain('example.com'), 'demo');
        $this->service->accept($this->manager, $mailbox);

        $this->assertTrue(UserEmails::for(AccountManager::users()->find('demo'))['recovery_missing']);

        $this->recovery->set('demo', 'backup@gmail.com');

        $this->assertFalse(UserEmails::for(AccountManager::users()->find('demo'))['recovery_missing']);
    }

    // --- who may have one

    public function test_only_standard_users_count_as_standard()
    {
        $user = User::where('username', 'demo')->first();
        $user->forceFill(['access_type' => AccessType::STANDARD])->save();

        $this->assertTrue(AccountManager::users()->find('demo')->isUserAccessType('standard'));
        $this->assertFalse(AccountManager::users()->find('demo')->isUserAccessType('basic'));

        $user->forceFill(['access_type' => AccessType::NONE])->save();

        $this->assertFalse(AccountManager::users()->find('demo')->isUserAccessType('standard'));
    }
}
