<?php

namespace Tests\Feature\Email;

use App\Mailbox;
use App\MailboxAddress;
use App\Services\Email\DatabaseMailAddressRegistry;
use App\Support\Facades\Organization;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class MailboxAddressTest extends TestCase
{
    use RefreshDatabase;

    private int $organization_id;

    protected function setUp(): void
    {
        parent::setUp();

        setupAccountManagerDriver('db');
        (new TestSupports)->seed();
        $user = User::where('username', 'demo')->firstOrFail();
        $this->actingAs($user);
        $this->organization_id = $user->organization->id;
    }

    private function mailbox(string $address, array $overrides = []): Mailbox
    {
        return Mailbox::create(array_merge([
            'organization_id' => $this->organization_id,
            'local_part' => strstr($address, '@', true),
            'address' => $address,
            'status' => Mailbox::ACTIVE,
        ], $overrides));
    }

    public function test_a_mailbox_answers_to_its_own_address_stored_in_lower_case()
    {
        $mailbox = $this->mailbox('Jane@Corp.org');

        $this->assertSame(['jane@corp.org'], $mailbox->addresses()->pluck('address')->all());
        $this->assertTrue($mailbox->addresses->first()->is_primary);
    }

    public function test_changing_the_mailbox_address_changes_its_primary_address()
    {
        $mailbox = $this->mailbox('jane@corp.org');
        $mailbox->addresses()->create(['address' => 'j@corp.org', 'is_primary' => false]);

        $mailbox->forceFill(['address' => 'jane.doe@corp.org'])->save();

        $this->assertEqualsCanonicalizing(['jane.doe@corp.org', 'j@corp.org'], $mailbox->addresses()->pluck('address')->all());
        $this->assertSame('jane.doe@corp.org', $mailbox->addresses()->where('is_primary', true)->value('address'));
    }

    public function test_removing_a_mailbox_removes_its_addresses()
    {
        $mailbox = $this->mailbox('jane@corp.org');
        $mailbox->addresses()->create(['address' => 'j@corp.org']);

        $mailbox->delete();

        $this->assertDatabaseCount('mailbox_addresses', 0);
    }

    public function test_an_address_can_only_belong_to_one_mailbox_whatever_its_case()
    {
        $this->mailbox('jane@corp.org');

        $this->expectException(QueryException::class);

        MailboxAddress::create(['mailbox_id' => $this->mailbox('other@corp.org')->id, 'address' => 'JANE@corp.org']);
    }

    public function test_a_mailbox_that_cannot_get_its_address_row_is_not_left_behind()
    {
        $holder = $this->mailbox('holder@corp.org');
        $holder->addresses()->create(['address' => 'taken@corp.org']);

        try {
            $this->mailbox('taken@corp.org');
            $this->fail('A mailbox was made at an address an alias already has');
        } catch (QueryException) {
            // expected
        }

        $this->assertDatabaseMissing('mailboxes', ['address' => 'taken@corp.org']);
    }

    public function test_the_registry_knows_aliases_and_ignores_case()
    {
        $mailbox = $this->mailbox('jane@corp.org');
        $mailbox->addresses()->create(['address' => 'sales@corp.org']);
        $registry = new DatabaseMailAddressRegistry;

        $this->assertTrue($registry->inUse('Sales@Corp.org'));
        $this->assertTrue($registry->inUse('jane@corp.org'));
        $this->assertFalse($registry->inUse('nobody@corp.org'));
    }

    public function test_the_count_is_the_active_mailboxes_of_the_organization_including_the_ones_users_have()
    {
        $this->mailbox('standalone@corp.org');
        $this->mailbox('person@corp.org', ['username' => 'demo']);
        $this->mailbox('offered@corp.org', ['username' => 'other', 'status' => Mailbox::PENDING]);
        $this->mailbox('elsewhere@corp.org', ['organization_id' => $this->organization_id + 1000]);

        $this->assertSame(2, Organization::countEmail());
    }
}
