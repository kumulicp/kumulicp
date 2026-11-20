<?php

namespace Tests\Feature\Email;

use App\Events\Mailboxes\MailboxAliasesChanged;
use App\Events\Mailboxes\MailboxDeactivated;
use App\Mailbox;
use App\OrgDomain;
use App\Services\Email\AliasDirectory;
use App\Services\Email\MailboxAliasService;
use App\Services\Email\MailboxException;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\Email\FakeAliasDirectory;
use Tests\Support\TestSupports;
use Tests\TestCase;

class MailboxAliasServiceTest extends TestCase
{
    use RefreshDatabase;

    private MailboxAliasService $service;

    private FakeAliasDirectory $directory;

    private Mailbox $mailbox;

    private OrgDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        setupAccountManagerDriver('db');
        (new TestSupports)->seed();
        $user = User::where('username', 'demo')->firstOrFail();

        $this->domain = OrgDomain::factory()->create([
            'organization_id' => $user->organization->id,
            'name' => 'corp.org',
            'status' => 'active',
            'email_enabled' => true,
            'email_status' => 'active',
        ]);

        $this->directory = new FakeAliasDirectory;
        $this->app->instance(AliasDirectory::class, $this->directory);
        $this->service = $this->app->make(MailboxAliasService::class);

        $this->mailbox = Mailbox::create([
            'organization_id' => $user->organization->id,
            'org_domain_id' => $this->domain->id,
            'local_part' => 'jane',
            'address' => 'jane@corp.org',
            'status' => Mailbox::ACTIVE,
        ]);
        $this->directory->entries['jane@corp.org'] = [];
    }

    private function refused(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('The change was allowed');
        } catch (MailboxException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_an_alias_is_a_second_address_of_the_mailbox_and_is_mirrored_to_the_directory()
    {
        Event::fake([MailboxAliasesChanged::class]);

        $alias = $this->service->add($this->mailbox, 'Sales', $this->domain);

        $this->assertSame('sales@corp.org', $alias->address);
        $this->assertFalse($alias->is_primary);
        $this->assertSame(['sales@corp.org'], $this->service->forMailbox($this->mailbox));
        $this->assertSame(['sales@corp.org'], $this->directory->entries['jane@corp.org']);
        Event::assertDispatched(MailboxAliasesChanged::class, fn ($event) => $event->mailbox->is($this->mailbox));
    }

    public function test_an_address_that_is_taken_cannot_be_an_alias()
    {
        Mailbox::create(['organization_id' => $this->mailbox->organization_id, 'local_part' => 'bob', 'address' => 'bob@corp.org', 'status' => Mailbox::ACTIVE]);
        $this->service->add($this->mailbox, 'sales', $this->domain);
        User::where('username', 'demo')->firstOrFail()->forceFill(['email' => 'demo@corp.org'])->save();

        foreach (['bob', 'sales', 'jane', 'demo'] as $name) {
            $this->refused(
                fn () => $this->service->add($this->mailbox, $name, $this->domain),
                __('organization.email.mailbox.taken', ['email' => $name.'@corp.org']),
            );
        }
    }

    public function test_only_a_mailbox_in_use_can_have_aliases()
    {
        $this->mailbox->forceFill(['status' => Mailbox::PENDING])->save();

        $this->refused(fn () => $this->service->add($this->mailbox, 'sales', $this->domain), __('organization.email.alias.mailbox_not_active'));
    }

    public function test_an_alias_has_to_be_on_a_domain_of_the_organization_that_has_email_on()
    {
        // Not saved: only what the service reads from them matters
        $other = OrgDomain::factory()->make(['organization_id' => $this->mailbox->organization_id + 1000, 'name' => 'other.org', 'email_enabled' => true, 'email_status' => 'active']);
        $off = OrgDomain::factory()->make(['organization_id' => $this->mailbox->organization_id, 'name' => 'off.org', 'email_enabled' => false, 'email_status' => 'disabled']);

        foreach ([$other, $off] as $domain) {
            $this->refused(fn () => $this->service->add($this->mailbox, 'sales', $domain), __('organization.email.alias.domain_not_allowed', ['domain' => $domain->name]));
        }
    }

    public function test_the_name_has_to_be_allowed_in_an_address()
    {
        $this->refused(fn () => $this->service->add($this->mailbox, 'not allowed', $this->domain), __('messages.rule.email_local_part'));
    }

    public function test_aliases_are_off_with_ldap_until_an_attribute_is_mapped()
    {
        config(['account_manager.driver' => 'ldap']);

        $this->refused(fn () => $this->service->add($this->mailbox, 'sales', $this->domain), __('organization.email.alias.disabled'));
    }

    public function test_the_number_of_aliases_can_be_limited()
    {
        config(['email.max_aliases' => 1]);
        $this->service->add($this->mailbox, 'sales', $this->domain);

        $this->refused(fn () => $this->service->add($this->mailbox, 'info', $this->domain), __('organization.email.alias.limit', ['count' => 1]));
    }

    public function test_an_alias_can_be_removed_but_not_the_primary_address_or_a_stranger()
    {
        $this->service->add($this->mailbox, 'sales', $this->domain);

        $this->service->remove($this->mailbox, 'Sales@corp.org');

        $this->assertSame([], $this->service->forMailbox($this->mailbox));
        $this->assertSame([], $this->directory->entries['jane@corp.org']);
        $this->assertDatabaseHas('mailbox_addresses', ['address' => 'jane@corp.org', 'is_primary' => true]);

        foreach (['jane@corp.org', 'sales@corp.org'] as $address) {
            $this->refused(fn () => $this->service->remove($this->mailbox, $address), __('organization.email.alias.not_found', ['email' => $address]));
        }
    }

    public function test_a_directory_that_fails_does_not_undo_the_alias()
    {
        $this->directory->failing = ['jane@corp.org'];

        $this->service->add($this->mailbox, 'sales', $this->domain);

        $this->assertSame(['sales@corp.org'], $this->service->forMailbox($this->mailbox));
    }

    public function test_nothing_is_written_to_the_directory_when_it_is_not_in_use()
    {
        $this->directory->enabled = false;

        $this->service->add($this->mailbox, 'sales', $this->domain);

        $this->assertSame([], $this->directory->writes);
    }

    public function test_the_aliases_go_when_the_mailbox_stops_being_in_use()
    {
        $this->service->add($this->mailbox, 'sales', $this->domain);
        $this->service->add($this->mailbox, 'info', $this->domain);

        event(new MailboxDeactivated($this->mailbox));

        $this->assertSame([], $this->service->forMailbox($this->mailbox));
        $this->assertSame([], $this->directory->entries['jane@corp.org']);
        $this->assertDatabaseHas('mailbox_addresses', ['address' => 'jane@corp.org', 'is_primary' => true]);
    }
}
