<?php

namespace Tests\Feature\Email;

use App\EmailForwarder;
use App\Events\Mailboxes\ForwarderChanged;
use App\Events\Mailboxes\ForwarderRemoved;
use App\Mailbox;
use App\Notifications\ForwarderDestinationConfirmation;
use App\OrgDomain;
use App\Support\Facades\Domain;
use App\Support\Facades\ServerInterface;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Email\FakeMailServer;
use Tests\Support\TestSupports;
use Tests\TestCase;

class EmailAccountsControllerTest extends TestCase
{
    use RefreshDatabase;

    private OrgDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        (new TestSupports)->seed();
        $user = User::where('username', 'demo')->firstOrFail();
        $this->actingAs($user);
        $organization = $user->organization;

        // The routes sit behind the emails feature and the plan has to include domains and email
        config(['toggle.flags.emails' => true]);
        $organization->plan->forceFill(['domain_enabled' => true, 'email_enabled' => true])->save();

        // A mail server for the domain to belong to
        DB::table('servers')->insert([[
            'id' => 2,
            'app_instance_id' => 0,
            'name' => 'Mail',
            'address' => 'mail.local',
            'host' => 'mail.local',
            'api_key' => 'api_key',
            'api_secret' => 'api_secret',
            'default_web_server' => 0,
            'default_email_server' => 1,
            'default_database_server' => 0,
            'internal_address' => 'localhost',
            'type' => 'email',
            'interface' => 'ldap',
            'settings' => '{}',
            'ip' => '127.0.0.1',
            'status' => 'active',
        ]]);
        DB::table('org_servers')->insert([['id' => 2, 'organization_id' => $organization->id, 'server_id' => 2]]);

        $this->domain = OrgDomain::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'example.com',
            'status' => 'active',
            'email_enabled' => true,
            'email_status' => 'active',
            'email_server_id' => 2,
        ]);
    }

    /**
     * Switching driver forgets the guards, which signs the user out: sign them back in afterwards.
     */
    private function useDatabaseAccountManager(): void
    {
        setupAccountManagerDriver('db');
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
    }

    /**
     * A mail server that has no mailboxes and cannot forward, standing in for the LDAP driver
     * (whose gates would otherwise count mailboxes in a real directory).
     */
    private function mailServerThatCannotForward(): void
    {
        ServerInterface::shouldReceive('supportsForwarders')->andReturn(false);
        ServerInterface::shouldReceive('connect')->andReturn(new class
        {
            public function emailList($domain): array
            {
                return [];
            }

            public function supportsForwarders(): bool
            {
                return false;
            }
        });
    }

    public function test_deleting_an_address_on_a_domain_the_organization_does_not_have_is_refused()
    {
        $response = $this->delete('/settings/email/accounts/jane@elsewhere.com');

        $response->assertRedirect('/settings/email/accounts');
        $response->assertSessionHas('error', __('organization.email.denied.exists', ['email' => 'jane@elsewhere.com']));
    }

    public function test_deleting_something_that_is_not_an_address_is_refused()
    {
        foreach (['jane', 'jane@', '@example.com', 'a@b@example.com'] as $not_an_address) {
            $response = $this->delete('/settings/email/accounts/'.rawurlencode($not_an_address));

            $response->assertRedirect('/settings/email/accounts');
            $response->assertSessionHas('error');
        }
    }

    public function test_updating_an_address_on_a_domain_the_organization_does_not_have_is_refused()
    {
        $response = $this->put('/settings/email/accounts/jane@elsewhere.com', ['name' => 'Jane']);

        $response->assertRedirect('/settings/email/accounts');
        $response->assertSessionHas('error', __('organization.email.denied.exists', ['email' => 'jane@elsewhere.com']));
    }

    public function test_the_forwarders_page_says_so_when_the_mail_server_cannot_forward()
    {
        $this->mailServerThatCannotForward();

        $response = $this->get('/settings/email/forwarders');

        $response->assertRedirect('/settings/email/accounts');
        $response->assertSessionHas('error', __('organization.email.denied.forwarders'));
    }

    public function test_adding_a_forwarder_is_refused_when_the_mail_server_cannot_forward()
    {
        $this->mailServerThatCannotForward();

        $response = $this->post('/settings/email/forwarders', [
            'forwarder' => 'new',
            'domain' => $this->domain->id,
            'new_forwarder' => 'sales',
            'destination' => 'someone@gmail.com',
        ]);

        $response->assertRedirect('/settings/email/accounts');
        $response->assertSessionHas('error', __('organization.email.denied.forwarders'));
    }

    private function mailServerThatForwards(): void
    {
        // What the plans and the feature allow is not what is being tested
        Gate::before(fn () => true);
        Event::fake([ForwarderChanged::class, ForwarderRemoved::class]);
        Notification::fake();

        // Asked of the interface, not of a connection: the pages open with the mail server down
        ServerInterface::shouldReceive('supportsForwarders')->andReturn(true);
        ServerInterface::shouldReceive('connect')->never();
    }

    private function forwarder(string $local_part = 'sales', string $destination = 'someone@gmail.com'): EmailForwarder
    {
        $forwarder = EmailForwarder::create(['organization_id' => $this->domain->organization_id, 'domain_id' => $this->domain->id, 'email' => $local_part.'@example.com']);
        $forwarder->destinations()->create(['destination' => $destination, 'kind' => 'external', 'confirmed_at' => now()]);

        return $forwarder;
    }

    public function test_a_new_forwarder_is_kept_and_an_outside_destination_is_asked_to_confirm()
    {
        $this->mailServerThatForwards();

        $response = $this->post('/settings/email/forwarders', [
            'forwarder' => 'new',
            'domain' => $this->domain->id,
            'new_forwarder' => 'Sales',
            'destination' => 'Someone@gmail.com',
        ]);

        $response->assertRedirect('/settings/email/forwarders');
        $response->assertSessionHas('success');
        $forwarder = EmailForwarder::where('email', 'sales@example.com')->firstOrFail();
        $this->assertSame(['someone@gmail.com'], $forwarder->destinations->pluck('destination')->all());
        $this->assertNull($forwarder->destinations->first()->confirmed_at);
        Event::assertDispatched(ForwarderChanged::class);
        Notification::assertSentOnDemand(ForwarderDestinationConfirmation::class);
    }

    public function test_another_destination_is_added_to_a_forwarder_that_exists()
    {
        $this->mailServerThatForwards();
        $forwarder = $this->forwarder();

        $response = $this->post('/settings/email/forwarders', ['forwarder' => $forwarder->id, 'destination' => 'other@gmail.com']);

        $response->assertRedirect('/settings/email/forwarders');
        $response->assertSessionHas('success');
        $this->assertEqualsCanonicalizing(['someone@gmail.com', 'other@gmail.com'], $forwarder->destinations()->pluck('destination')->all());
    }

    public function test_what_the_forwarder_service_refuses_is_said_and_nothing_is_kept()
    {
        $this->mailServerThatForwards();

        $response = $this->post('/settings/email/forwarders', [
            'forwarder' => 'new',
            'domain' => $this->domain->id,
            'new_forwarder' => 'sales',
            'destination' => 'sales@example.com',
        ]);

        $response->assertRedirect('/settings/email/forwarders');
        $response->assertSessionHas('error', __('organization.email.forwarder.itself'));
        $this->assertDatabaseCount('email_forwarders', 0);
    }

    public function test_the_forwarders_page_lists_what_the_database_has_and_what_is_waiting()
    {
        $this->mailServerThatForwards();
        $forwarder = $this->forwarder();
        $forwarder->destinations()->create(['destination' => 'waiting@gmail.com', 'kind' => 'external']);

        $this->get('/settings/email/forwarders')->assertInertia(fn (Assert $page) => $page
            ->component('Organization/Settings/EmailAccounts/EmailForwarders')
            ->where('forwarders.0.address', 'sales@example.com')
            ->where('forwarders.0.status', 'pending')
            ->where('forwarders.0.destinations.0.waiting', false)
            ->where('forwarders.0.destinations.1.address', 'waiting@gmail.com')
            ->where('forwarders.0.destinations.1.waiting', true));
    }

    public function test_the_forwarders_page_offers_every_email_domain_by_id_including_ones_the_organization_brought()
    {
        $this->mailServerThatForwards();
        $own = OrgDomain::factory()->create([
            'organization_id' => $this->domain->organization_id,
            'name' => 'brought.org',
            'type' => 'connection',
            'status' => 'active',
            'email_enabled' => true,
            'email_status' => 'active',
            'email_server_id' => 2,
        ]);

        $this->get('/settings/email/forwarders')->assertInertia(fn (Assert $page) => $page
            ->has('domains', 2)
            ->where('domains.0.text', 'brought.org')
            ->where('domains.0.value', $own->id)
            ->where('domains.1.value', $this->domain->id));
    }

    public function test_a_forwarder_is_removed()
    {
        $this->mailServerThatForwards();
        $forwarder = $this->forwarder();

        $response = $this->delete('/settings/email/forwarders/'.$forwarder->id);

        $response->assertRedirect('/settings/email/forwarders');
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('email_forwarders', ['id' => $forwarder->id]);
        Event::assertDispatched(ForwarderRemoved::class, fn ($event) => $event->address === 'sales@example.com');
    }

    public function test_one_destination_is_removed_but_not_the_last()
    {
        $this->mailServerThatForwards();
        $forwarder = $this->forwarder();
        $forwarder->destinations()->create(['destination' => 'other@gmail.com', 'kind' => 'external', 'confirmed_at' => now()]);

        $this->delete('/settings/email/forwarders/'.$forwarder->id.'/destinations/'.rawurlencode('other@gmail.com'))->assertSessionHas('success');
        $this->assertSame(['someone@gmail.com'], $forwarder->destinations()->pluck('destination')->all());

        $this->delete('/settings/email/forwarders/'.$forwarder->id.'/destinations/'.rawurlencode('someone@gmail.com'))
            ->assertSessionHas('error', __('organization.email.forwarder.last_destination'));
        $this->assertSame(1, $forwarder->destinations()->count());
    }

    public function test_another_organizations_forwarder_cannot_be_removed()
    {
        $this->mailServerThatForwards();
        $forwarder = $this->forwarder();
        $forwarder->forceFill(['organization_id' => $forwarder->organization_id + 100])->save();

        $this->delete('/settings/email/forwarders/'.$forwarder->id)->assertNotFound();
        $this->delete('/settings/email/forwarders/'.$forwarder->id.'/destinations/'.rawurlencode('someone@gmail.com'))->assertNotFound();
        $this->post('/settings/email/forwarders', ['forwarder' => $forwarder->id, 'destination' => 'other@gmail.com'])->assertNotFound();

        $this->assertDatabaseHas('email_forwarders', ['id' => $forwarder->id]);
    }

    public function test_a_standalone_mailbox_is_recorded_when_it_is_added_and_forgotten_when_it_is_deleted()
    {
        // What the plan and the feature allow is not what is being tested
        Gate::before(fn () => true);
        $this->useDatabaseAccountManager();
        $server = new FakeMailServer;
        Domain::shouldReceive('connect')->andReturn($server);

        $this->post('/settings/email/accounts', [
            'name' => 'Sales',
            'email' => 'sales',
            'domain' => $this->domain->id,
            'password' => 'Passw0rd!',
            'password_confirmation' => 'Passw0rd!',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('mailboxes', [
            'address' => 'sales@example.com',
            'username' => null,
            'status' => Mailbox::ACTIVE,
            'org_domain_id' => $this->domain->id,
            'display_name' => 'Sales',
        ]);
        $this->assertDatabaseHas('mailbox_addresses', ['address' => 'sales@example.com', 'is_primary' => true]);

        $this->delete('/settings/email/accounts/sales@example.com')->assertSessionHas('success');

        $this->assertDatabaseMissing('mailboxes', ['address' => 'sales@example.com']);
        $this->assertDatabaseCount('mailbox_addresses', 0);
    }

    public function test_a_mailbox_the_server_could_not_make_is_not_recorded()
    {
        Gate::before(fn () => true);
        $this->useDatabaseAccountManager();
        $server = new FakeMailServer;
        $server->failing = true;
        Domain::shouldReceive('connect')->andReturn($server);

        $this->post('/settings/email/accounts', [
            'name' => 'Sales',
            'email' => 'sales',
            'domain' => $this->domain->id,
            'password' => 'Passw0rd!',
            'password_confirmation' => 'Passw0rd!',
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('mailboxes', 0);
    }

    private function mailServerThatIsDown(): void
    {
        Domain::shouldReceive('connect')->andThrow(new \Exception('Unable to connect to Stalwart'));
        ServerInterface::shouldReceive('connect')->andThrow(new \Exception('Unable to connect to Stalwart'));
        ServerInterface::shouldReceive('supportsAliases')->andReturn(false);
    }

    public function test_the_email_accounts_page_lists_the_recorded_mailboxes_without_the_mail_server()
    {
        Gate::before(fn () => true);
        $this->mailServerThatIsDown();
        $mailbox = Mailbox::create([
            'organization_id' => $this->domain->organization_id,
            'org_domain_id' => $this->domain->id,
            'local_part' => 'sales',
            'address' => 'sales@example.com',
            'display_name' => 'Sales',
            'status' => Mailbox::ACTIVE,
        ]);
        $mailbox->addresses()->create(['address' => 'info@example.com']);
        // Not standalone, and not in use: neither is listed
        Mailbox::create(['organization_id' => $this->domain->organization_id, 'org_domain_id' => $this->domain->id, 'local_part' => 'jane', 'address' => 'jane@example.com', 'username' => 'demo', 'status' => Mailbox::ACTIVE]);
        Mailbox::create(['organization_id' => $this->domain->organization_id, 'org_domain_id' => $this->domain->id, 'local_part' => 'offer', 'address' => 'offer@example.com', 'status' => Mailbox::PENDING]);

        $this->get('/settings/email/accounts')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Organization/Settings/EmailAccounts/EmailAccountsList')
            ->has('accounts', 1)
            ->where('accounts.0.email', 'sales@example.com')
            ->where('accounts.0.name', 'Sales')
            ->where('accounts.0.mailbox_id', $mailbox->id)
            ->where('accounts.0.aliases', ['info@example.com']));
    }

    public function test_the_forwarders_page_opens_without_the_mail_server()
    {
        Gate::before(fn () => true);
        $this->mailServerThatIsDown();
        ServerInterface::shouldReceive('supportsForwarders')->andReturn(true);

        $this->get('/settings/email/forwarders')->assertOk();
    }

    public function test_changing_an_account_while_the_mail_server_is_down_says_so_and_changes_nothing()
    {
        Gate::before(fn () => true);
        $this->mailServerThatIsDown();
        $mailbox = Mailbox::create(['organization_id' => $this->domain->organization_id, 'org_domain_id' => $this->domain->id, 'local_part' => 'sales', 'address' => 'sales@example.com', 'display_name' => 'Sales', 'status' => Mailbox::ACTIVE]);

        $this->post('/settings/email/accounts', ['name' => 'New', 'email' => 'new', 'domain' => $this->domain->id, 'password' => 'Passw0rd!', 'password_confirmation' => 'Passw0rd!'])
            ->assertSessionHas('error', __('organization.email.denied.unavailable'));
        $this->put('/settings/email/accounts/sales@example.com', ['name' => 'Renamed'])
            ->assertSessionHas('error', __('organization.email.denied.unavailable'));
        $this->delete('/settings/email/accounts/sales@example.com')
            ->assertSessionHas('error', __('organization.email.denied.unavailable'));

        $this->assertSame(['sales@example.com'], Mailbox::pluck('address')->all());
        $this->assertSame('Sales', $mailbox->fresh()->display_name);
    }
}
