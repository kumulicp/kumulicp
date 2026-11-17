<?php

namespace Tests\Feature\Email;

use App\Events\Mailboxes\MailboxActivated;
use App\Events\Users\UserUpdated;
use App\Mailbox;
use App\OrgDomain;
use App\Services\Email\LegacyOrgMailMigrator;
use App\Services\Email\MailboxService;
use App\Services\Email\RecoveryEmailDirectory;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Domain;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Email\FakeLegacyOrgMailSource;
use Tests\Support\Email\FakeMailServer;
use Tests\Support\Email\FakeRecoveryEmailDirectory;
use Tests\Support\TestSupports;
use Tests\TestCase;

class LegacyOrgMailMigratorTest extends TestCase
{
    use RefreshDatabase;

    private FakeLegacyOrgMailSource $source;

    private LegacyOrgMailMigrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();

        setupAccountManagerDriver('db');
        $support = new TestSupports;
        $support->seed();
        $support->addUsers();
        $this->actingAs(User::where('username', 'demo')->firstOrFail());

        Event::fake([UserUpdated::class, MailboxActivated::class]);
        Notification::fake();
        Domain::shouldReceive('connect')->andReturn(new FakeMailServer);
        $this->app->instance(RecoveryEmailDirectory::class, new FakeRecoveryEmailDirectory);

        $this->source = new FakeLegacyOrgMailSource;
        $this->app->instance(\App\Services\Email\LegacyOrgMailSource::class, $this->source);
        $this->migrator = $this->app->make(LegacyOrgMailMigrator::class);

        foreach (['corp.org', 'example.com'] as $name) {
            OrgDomain::factory()->create(['organization_id' => User::where('username', 'demo')->first()->organization->id, 'name' => $name]);
        }
    }

    private function email(string $username): string
    {
        return User::where('username', $username)->first()->email;
    }

    public function test_a_dry_run_changes_nothing_and_says_what_it_would_do()
    {
        $this->source->values = ['testing1' => ['testing1@corp.org'], 'testing2' => ['testing2@example.com']];

        $rows = $this->migrator->run();

        $this->assertSame(['would offer', 'would host'], array_column($rows, 'action'));
        $this->assertDatabaseCount('mailboxes', 0);
        $this->assertSame([], $this->source->cleared);
    }

    public function test_an_address_that_is_not_the_one_a_user_signs_in_with_is_offered_and_their_email_is_left_alone()
    {
        $this->source->values = ['testing1' => ['Testing1@Corp.org']];

        $rows = $this->migrator->run(apply: true);

        $this->assertSame('offered', $rows[0]['action']);
        $this->assertDatabaseHas('mailboxes', ['address' => 'testing1@corp.org', 'status' => Mailbox::PENDING, 'username' => 'testing1']);
        $this->assertSame('testing1@example.com', $this->email('testing1'));
        $this->assertSame([], $this->source->cleared, 'kept until the user has accepted');
    }

    public function test_the_address_a_user_signs_in_with_is_hosted_and_the_old_value_cleared()
    {
        $this->source->values = ['testing2' => ['testing2@example.com']];

        $rows = $this->migrator->run(apply: true);

        $this->assertSame('hosted', $rows[0]['action']);
        $this->assertDatabaseHas('mailboxes', ['address' => 'testing2@example.com', 'status' => Mailbox::ACTIVE]);
        $this->assertSame('testing2@example.com', $this->email('testing2'));
        $this->assertSame([['testing2', 'testing2@example.com']], $this->source->cleared);
    }

    public function test_a_user_with_several_addresses_is_left_for_a_decision()
    {
        $this->source->values = ['testing1' => ['testing1@corp.org', 'sales@corp.org']];

        $rows = $this->migrator->run(apply: true);

        $this->assertSame('skipped', $rows[0]['action']);
        $this->assertDatabaseCount('mailboxes', 0);
    }

    public function test_an_address_on_a_domain_the_organization_does_not_have_is_skipped()
    {
        $this->source->values = ['testing1' => ['testing1@elsewhere.net']];

        $rows = $this->migrator->run(apply: true);

        $this->assertSame('skipped', $rows[0]['action']);
        $this->assertDatabaseCount('mailboxes', 0);
    }

    public function test_an_address_someone_else_has_is_skipped_with_the_reason()
    {
        Mailbox::create(['organization_id' => 1, 'local_part' => 'testing1', 'address' => 'testing1@corp.org', 'username' => 'someone-else']);
        $this->source->values = ['testing1' => ['testing1@corp.org']];

        $rows = $this->migrator->run(apply: true);

        $this->assertSame('skipped', $rows[0]['action']);
        $this->assertSame(__('organization.email.mailbox.taken', ['email' => 'testing1@corp.org']), $rows[0]['reason']);
    }

    public function test_an_offer_waits_until_the_user_accepts_and_a_later_run_then_clears_the_old_value()
    {
        $this->source->values = ['testing1' => ['testing1@corp.org']];
        $this->migrator->run(apply: true);

        $this->assertSame('waiting', $this->migrator->run(apply: true)[0]['action']);

        $user = AccountManager::users()->find('testing1');
        $service = $this->app->make(MailboxService::class);
        $service->accept($user, $service->forUser('testing1'));

        $this->assertSame('would clear', $this->migrator->run()[0]['action']);
        $this->assertSame([], $this->source->cleared);

        $this->assertSame('cleared', $this->migrator->run(apply: true)[0]['action']);
        $this->assertSame([['testing1', 'testing1@corp.org']], $this->source->cleared);
        $this->assertSame([], $this->migrator->run(apply: true));
    }

    public function test_it_can_be_limited_to_one_user()
    {
        $this->source->values = ['testing1' => ['testing1@corp.org'], 'testing2' => ['testing2@corp.org']];

        $rows = $this->migrator->run(apply: true, username: 'testing2');

        $this->assertCount(1, $rows);
        $this->assertDatabaseMissing('mailboxes', ['username' => 'testing1']);
        $this->assertDatabaseHas('mailboxes', ['username' => 'testing2']);
    }

    public function test_the_command_says_there_is_nothing_to_do_without_ldap()
    {
        config(['account_manager.driver' => 'db']);

        $this->artisan('email:migrate-org-mail')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();
    }
}
