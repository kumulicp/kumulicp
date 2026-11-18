<?php

namespace Tests\Feature\Email;

use App\Events\Mailboxes\MailboxActivated;
use App\Events\Mailboxes\MailboxDeactivated;
use App\Events\Users\UserUpdated;
use App\Mailbox;
use App\OrgDomain;
use App\Services\Email\MailboxException;
use App\Services\Email\MailboxService;
use App\Services\Email\RecoveryEmailDirectory;
use App\Services\Email\RecoveryEmailService;
use App\Support\AccountManager\UserManager;
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

}
