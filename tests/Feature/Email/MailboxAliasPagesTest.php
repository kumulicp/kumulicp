<?php

namespace Tests\Feature\Email;

use App\Enums\AccessType;
use App\Mailbox;
use App\OrgDomain;
use App\Services\Email\AliasDirectory;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Email\FakeAliasDirectory;
use Tests\Support\TestSupports;
use Tests\TestCase;

class MailboxAliasPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private OrgDomain $domain;

    private Mailbox $personal;

    private Mailbox $standalone;

    protected function setUp(): void
    {
        parent::setUp();

        setupAccountManagerDriver('db');
        (new TestSupports)->seed();
        $this->user = User::where('username', 'demo')->firstOrFail();
        $this->user->forceFill(['access_type' => AccessType::STANDARD])->save();
        $this->actingAs($this->user);
        $directory = new FakeAliasDirectory;
        $directory->enabled = false;
        $this->app->instance(AliasDirectory::class, $directory);
        // What the plan and the feature allow is not what is being tested here
        Gate::before(fn () => true);

        $organization_id = $this->user->organization->id;
        $this->domain = OrgDomain::factory()->create([
            'organization_id' => $organization_id,
            'name' => 'corp.org',
            'status' => 'active',
            'email_enabled' => true,
            'email_status' => 'active',
        ]);
        $this->personal = Mailbox::create(['organization_id' => $organization_id, 'org_domain_id' => $this->domain->id, 'local_part' => 'demo', 'address' => 'demo@corp.org', 'username' => 'demo', 'status' => Mailbox::ACTIVE]);
        $this->standalone = Mailbox::create(['organization_id' => $organization_id, 'org_domain_id' => $this->domain->id, 'local_part' => 'sales', 'address' => 'sales@corp.org', 'status' => Mailbox::ACTIVE]);
    }

    public function test_an_administrator_adds_an_alias_to_a_users_organization_address()
    {
        $this->post("/mailboxes/{$this->personal->id}/aliases", ['local_part' => 'Boss', 'domain' => $this->domain->id])
            ->assertRedirect('/users/demo/edit')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('mailbox_addresses', ['mailbox_id' => $this->personal->id, 'address' => 'boss@corp.org', 'is_primary' => false]);
    }

    public function test_the_user_page_lists_the_aliases_of_someone_who_has_an_organization_address()
    {
        $this->personal->addresses()->create(['address' => 'boss@corp.org']);

        $this->get('/users/demo/edit')->assertInertia(fn ($page) => $page
            ->where('aliases.mailbox_id', $this->personal->id)
            ->where('aliases.enabled', true)
            ->where('aliases.list', ['boss@corp.org'])
            ->where('aliases.domains.0.name', 'corp.org'));
    }

    public function test_the_user_page_has_no_aliases_for_someone_without_an_organization_address()
    {
        $this->personal->forceFill(['status' => Mailbox::PENDING])->save();

        $this->get('/users/demo/edit')->assertInertia(fn ($page) => $page->where('aliases', null));
    }

    public function test_an_alias_is_removed()
    {
        $this->personal->addresses()->create(['address' => 'boss@corp.org']);

        $this->delete("/mailboxes/{$this->personal->id}/aliases/boss@corp.org")
            ->assertRedirect('/users/demo/edit')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('mailbox_addresses', ['address' => 'boss@corp.org']);
    }

    public function test_a_standalone_mailbox_gets_aliases_from_the_email_accounts_page()
    {
        $this->post("/mailboxes/{$this->standalone->id}/aliases", ['local_part' => 'info', 'domain' => $this->domain->id])
            ->assertRedirect('/settings/email/accounts')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('mailbox_addresses', ['mailbox_id' => $this->standalone->id, 'address' => 'info@corp.org']);
    }

    public function test_a_refused_alias_says_why_and_adds_nothing()
    {
        $this->post("/mailboxes/{$this->personal->id}/aliases", ['local_part' => 'sales', 'domain' => $this->domain->id])
            ->assertRedirect('/users/demo/edit')
            ->assertSessionHas('error', __('organization.email.mailbox.taken', ['email' => 'sales@corp.org']));

        $this->assertDatabaseCount('mailbox_addresses', 2);
    }

    public function test_aliases_stay_off_with_ldap_until_an_attribute_is_mapped()
    {
        config(['account_manager.driver' => 'ldap']);

        $this->post("/mailboxes/{$this->standalone->id}/aliases", ['local_part' => 'info', 'domain' => $this->domain->id])
            ->assertSessionHas('error', __('organization.email.alias.disabled'));
    }

    public function test_another_organizations_mailbox_is_not_found()
    {
        $other = Mailbox::create(['organization_id' => $this->user->organization->id + 1000, 'local_part' => 'x', 'address' => 'x@other.org', 'status' => Mailbox::ACTIVE]);

        $this->post("/mailboxes/{$other->id}/aliases", ['local_part' => 'info', 'domain' => $this->domain->id])->assertNotFound();
        $this->delete("/mailboxes/{$other->id}/aliases/x@other.org")->assertNotFound();
    }

    public function test_someone_who_may_not_edit_the_user_cannot_change_their_aliases()
    {
        Gate::before(fn ($user, $ability) => $ability === 'edit-user' ? false : true);

        $this->post("/mailboxes/{$this->personal->id}/aliases", ['local_part' => 'info', 'domain' => $this->domain->id])->assertForbidden();

        $this->assertDatabaseMissing('mailbox_addresses', ['address' => 'info@corp.org']);
    }
}
