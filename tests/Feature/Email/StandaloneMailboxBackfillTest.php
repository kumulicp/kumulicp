<?php

namespace Tests\Feature\Email;

use App\Mailbox;
use App\OrgDomain;
use App\Services\Email\StandaloneMailboxBackfill;
use App\Support\Facades\Domain;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Email\FakeMailServer;
use Tests\Support\TestSupports;
use Tests\TestCase;

class StandaloneMailboxBackfillTest extends TestCase
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

        $this->domain = OrgDomain::factory()->create([
            'organization_id' => $this->user->organization->id,
            'name' => 'corp.org',
            'status' => 'active',
            'email_enabled' => true,
            'email_status' => 'active',
        ]);

        $this->server = new FakeMailServer;
        Domain::shouldReceive('connect')->andReturn($this->server);
    }

    private function run_backfill(bool $apply = true): array
    {
        return app(StandaloneMailboxBackfill::class)->run($apply, $this->user->organization->slug);
    }

    private function actions(array $rows): array
    {
        return array_column($rows, 'action', 'address');
    }

    public function test_a_mailbox_on_the_server_that_the_panel_does_not_have_is_recorded()
    {
        $this->server->emails = ['Sales@corp.org' => 'Sales', 'info@corp.org' => 'Info'];

        $rows = $this->run_backfill();

        $this->assertSame(['sales@corp.org' => 'recorded', 'info@corp.org' => 'recorded'], $this->actions($rows));
        $this->assertDatabaseHas('mailboxes', [
            'address' => 'sales@corp.org',
            'username' => null,
            'status' => Mailbox::ACTIVE,
            'org_domain_id' => $this->domain->id,
            'organization_id' => $this->user->organization->id,
            'display_name' => 'Sales',
        ]);
        $this->assertDatabaseHas('mailbox_addresses', ['address' => 'sales@corp.org', 'is_primary' => true]);
    }

    public function test_running_it_again_records_nothing_more()
    {
        $this->server->emails = ['sales@corp.org' => 'Sales'];
        $this->run_backfill();

        $second = $this->run_backfill();

        $this->assertSame([], $second);
        $this->assertDatabaseCount('mailboxes', 1);
    }

    public function test_a_dry_run_records_nothing()
    {
        $this->server->emails = ['sales@corp.org' => 'Sales'];

        $rows = $this->run_backfill(apply: false);

        $this->assertSame(['sales@corp.org' => 'would record'], $this->actions($rows));
        $this->assertDatabaseCount('mailboxes', 0);
    }

    public function test_an_address_a_user_signs_in_with_is_reported_and_not_recorded()
    {
        $this->user->forceFill(['email' => 'demo@corp.org'])->save();
        $this->server->emails = ['demo@corp.org' => 'Demo'];

        $rows = $this->run_backfill();

        $this->assertSame(['demo@corp.org' => 'collision'], $this->actions($rows));
        $this->assertDatabaseCount('mailboxes', 0);
    }

    public function test_an_address_recorded_for_another_organization_or_a_user_is_reported()
    {
        Mailbox::create(['organization_id' => $this->user->organization->id + 1000, 'local_part' => 'theirs', 'address' => 'theirs@corp.org', 'status' => Mailbox::ACTIVE]);
        Mailbox::create(['organization_id' => $this->user->organization->id, 'local_part' => 'jane', 'address' => 'jane@corp.org', 'username' => 'jane', 'status' => Mailbox::ACTIVE]);
        $this->server->emails = ['theirs@corp.org' => 'Theirs', 'jane@corp.org' => 'Jane'];

        $rows = $this->run_backfill();

        $this->assertSame(['theirs@corp.org' => 'collision', 'jane@corp.org' => 'collision'], $this->actions($rows));
        $this->assertDatabaseCount('mailboxes', 2);
    }

    public function test_a_recorded_mailbox_the_server_no_longer_has_is_reported_and_kept()
    {
        Mailbox::create([
            'organization_id' => $this->user->organization->id,
            'org_domain_id' => $this->domain->id,
            'local_part' => 'gone',
            'address' => 'gone@corp.org',
            'status' => Mailbox::ACTIVE,
        ]);

        $rows = $this->run_backfill();

        $this->assertSame(['gone@corp.org' => 'missing'], $this->actions($rows));
        $this->assertDatabaseHas('mailboxes', ['address' => 'gone@corp.org']);
    }

    public function test_a_server_that_cannot_be_asked_is_reported_without_stopping_the_rest()
    {
        $this->server->failing = true;

        $rows = $this->run_backfill();

        $this->assertSame(['@corp.org' => 'failed'], $this->actions($rows));
    }
}
