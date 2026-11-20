<?php

namespace Tests\Feature\Email;

use App\Mailbox;
use App\OrgDomain;
use App\Support\Facades\Domain;
use App\Support\Facades\ServerInterface;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
     * A mail server that has no mailboxes and cannot forward, standing in for the LDAP driver
     * (whose gates would otherwise count mailboxes in a real directory).
     */
    private function mailServerThatCannotForward(): void
    {
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

    public function test_a_standalone_mailbox_is_recorded_when_it_is_added_and_forgotten_when_it_is_deleted()
    {
        // What the plan and the feature allow is not what is being tested
        Gate::before(fn () => true);
        setupAccountManagerDriver('db');
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
        setupAccountManagerDriver('db');
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
}
