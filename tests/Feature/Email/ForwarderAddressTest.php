<?php

namespace Tests\Feature\Email;

use App\EmailForwarder;
use App\EmailForwarderDestination;
use App\Mailbox;
use App\MailboxAddress;
use App\OrgDomain;
use App\Services\Email\DatabaseMailAddressRegistry;
use App\Services\Email\StandaloneMailboxBackfill;
use App\Support\Facades\Domain;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Email\FakeMailServer;
use Tests\Support\TestSupports;
use Tests\TestCase;

class ForwarderAddressTest extends TestCase
{
    use RefreshDatabase;

    private int $organization_id;

    private OrgDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        setupAccountManagerDriver('db');
        (new TestSupports)->seed();
        $user = User::where('username', 'demo')->firstOrFail();
        $this->actingAs($user);
        $this->organization_id = $user->organization->id;
        $this->domain = OrgDomain::factory()->create([
            'organization_id' => $this->organization_id,
            'name' => 'corp.org',
            'status' => 'active',
            'email_enabled' => true,
            'email_status' => 'active',
        ]);
    }

    private function forwarder(string $address): EmailForwarder
    {
        return EmailForwarder::create([
            'organization_id' => $this->organization_id,
            'domain_id' => $this->domain->id,
            'email' => $address,
        ]);
    }

    public function test_a_forwarder_has_its_address_in_lower_case_and_starts_pending()
    {
        $forwarder = $this->forwarder('Sales@Corp.org')->fresh();

        $this->assertSame('sales@corp.org', $forwarder->email);
        $this->assertSame(EmailForwarder::PENDING, $forwarder->status);
        $this->assertNull($forwarder->applied_at);
        $this->assertDatabaseHas('mailbox_addresses', ['forwarder_id' => $forwarder->id, 'mailbox_id' => null, 'address' => 'sales@corp.org', 'is_primary' => true]);
    }

    public function test_changing_the_address_changes_its_row()
    {
        $forwarder = $this->forwarder('sales@corp.org');

        $forwarder->update(['email' => 'Info@corp.org']);

        $this->assertSame(['info@corp.org'], $forwarder->address()->pluck('address')->all());
    }

    public function test_an_address_is_taken_by_a_forwarder_for_mailboxes_and_aliases_too()
    {
        $this->forwarder('sales@corp.org');

        $this->assertTrue((new DatabaseMailAddressRegistry)->inUse('SALES@corp.org'));

        try {
            Mailbox::create(['organization_id' => $this->organization_id, 'local_part' => 'sales', 'address' => 'sales@corp.org', 'status' => Mailbox::ACTIVE]);
            $this->fail('A mailbox was made at a forwarder\'s address');
        } catch (QueryException) {
            // expected
        }

        $this->assertDatabaseMissing('mailboxes', ['address' => 'sales@corp.org']);
    }

    public function test_a_forwarder_cannot_take_an_address_a_mailbox_or_an_alias_has()
    {
        $mailbox = Mailbox::create(['organization_id' => $this->organization_id, 'local_part' => 'jane', 'address' => 'jane@corp.org', 'status' => Mailbox::ACTIVE]);
        $mailbox->addresses()->create(['address' => 'boss@corp.org']);

        foreach (['jane@corp.org', 'BOSS@corp.org'] as $taken) {
            try {
                $this->forwarder($taken);
                $this->fail("A forwarder was made at $taken");
            } catch (QueryException) {
                // expected
            }
        }

        $this->assertDatabaseCount('email_forwarders', 0);
    }

    public function test_removing_a_forwarder_frees_its_address_and_removes_its_destinations()
    {
        $forwarder = $this->forwarder('sales@corp.org');
        $forwarder->destinations()->create(['destination' => 'bob@gmail.com', 'kind' => EmailForwarderDestination::EXTERNAL]);

        $forwarder->delete();

        $this->assertDatabaseCount('mailbox_addresses', 0);
        $this->assertDatabaseCount('email_forwarder_destinations', 0);
        $this->assertFalse((new DatabaseMailAddressRegistry)->inUse('sales@corp.org'));
    }

    public function test_a_destination_is_stored_in_lower_case_once_per_forwarder()
    {
        $forwarder = $this->forwarder('sales@corp.org');
        $forwarder->destinations()->create(['destination' => 'Bob@Gmail.com', 'kind' => EmailForwarderDestination::EXTERNAL]);

        $this->assertSame(['bob@gmail.com'], $forwarder->destinations()->pluck('destination')->all());

        $this->expectException(QueryException::class);

        $forwarder->destinations()->create(['destination' => 'BOB@gmail.com', 'kind' => EmailForwarderDestination::EXTERNAL]);
    }

    public function test_an_internal_destination_is_live_and_an_external_one_only_once_confirmed()
    {
        $forwarder = $this->forwarder('sales@corp.org');
        $internal = $forwarder->destinations()->create(['destination' => 'jane@corp.org', 'kind' => EmailForwarderDestination::INTERNAL]);
        $external = $forwarder->destinations()->create(['destination' => 'bob@gmail.com', 'kind' => EmailForwarderDestination::EXTERNAL]);

        $this->assertTrue($internal->isLive());
        $this->assertFalse($external->isLive());

        $external->forceFill(['confirmed_at' => now()])->save();

        $this->assertTrue($external->fresh()->isLive());
    }

    public function test_an_address_belongs_to_a_mailbox_or_a_forwarder_and_only_one()
    {
        $forwarder = $this->forwarder('sales@corp.org');
        $mailbox = Mailbox::create(['organization_id' => $this->organization_id, 'local_part' => 'jane', 'address' => 'jane@corp.org', 'status' => Mailbox::ACTIVE]);

        foreach ([[], ['mailbox_id' => $mailbox->id, 'forwarder_id' => $forwarder->id]] as $owners) {
            try {
                MailboxAddress::create($owners + ['address' => 'x@corp.org']);
                $this->fail('An address with the wrong owners was saved');
            } catch (\InvalidArgumentException) {
                // expected
            }
        }
    }

    public function test_the_mailbox_backfill_reports_an_address_a_forwarder_has_and_does_not_record_it()
    {
        $this->forwarder('sales@corp.org');
        $server = new FakeMailServer;
        $server->emails = ['sales@corp.org' => 'Sales'];
        Domain::shouldReceive('connect')->andReturn($server);

        $rows = app(StandaloneMailboxBackfill::class)->run(true, User::where('username', 'demo')->first()->organization->slug);

        $this->assertSame(['sales@corp.org' => 'collision'], array_column($rows, 'action', 'address'));
        $this->assertDatabaseCount('mailboxes', 0);
    }
}
