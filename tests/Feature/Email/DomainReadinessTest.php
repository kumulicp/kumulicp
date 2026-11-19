<?php

namespace Tests\Feature\Email;

use App\Enums\AccessType;
use App\Mailbox;
use App\OrgDomain;
use App\Services\Email\DomainReadiness;
use App\Services\Email\RecoveryEmailDirectory;
use App\Services\Email\RecoveryEmailService;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Email\FakeRecoveryEmailDirectory;
use Tests\Support\TestSupports;
use Tests\TestCase;

class DomainReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private OrgDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        setupAccountManagerDriver('db');
        (new TestSupports)->seed();
        $this->user = User::where('username', 'demo')->firstOrFail();
        $this->user->forceFill(['access_type' => AccessType::STANDARD])->save();
        $this->actingAs($this->user);
        $this->app->instance(RecoveryEmailDirectory::class, new FakeRecoveryEmailDirectory);

        // The domain the user's email is on, moving here from another provider
        $this->domain = OrgDomain::factory()->create([
            'organization_id' => $this->user->organization->id,
            'name' => 'example.com',
            'status' => 'active',
            'email_enabled' => false,
            'email_status' => 'waiting_dns',
        ]);
    }

    private function names(array $people): array
    {
        return array_column($people, 'username');
    }

    public function test_a_user_with_an_email_on_the_domain_and_no_mailbox_is_not_ready()
    {
        $readiness = app(DomainReadiness::class)->for($this->domain);

        $this->assertFalse($readiness['ready']);
        $this->assertContains('demo', $this->names($readiness['without_mailbox']));
        $this->assertGreaterThanOrEqual(1, $readiness['without_mailbox_count']);
    }

    public function test_a_user_who_cannot_have_a_mailbox_is_listed_but_not_offered_one()
    {
        $this->user->forceFill(['access_type' => AccessType::BASIC])->save();

        $readiness = app(DomainReadiness::class)->for($this->domain);
        $demo = collect($readiness['without_mailbox'])->firstWhere('username', 'demo');

        $this->assertFalse($demo['can_offer']);

        $result = app(DomainReadiness::class)->offerExisting($this->domain);

        $this->assertContains('demo', $result['skipped']);
        $this->assertDatabaseMissing('mailboxes', ['username' => 'demo']);
    }

    public function test_offering_the_address_they_already_have_makes_the_domain_ready_and_changes_nothing()
    {
        $result = app(DomainReadiness::class)->offerExisting($this->domain);

        $this->assertGreaterThanOrEqual(1, $result['offered']);
        $this->assertDatabaseHas('mailboxes', [
            'username' => 'demo',
            'address' => 'demo@example.com',
            'status' => Mailbox::PENDING,
            'org_domain_id' => $this->domain->id,
        ]);
        $this->assertSame('demo@example.com', $this->user->fresh()->email);

        $readiness = app(DomainReadiness::class)->for($this->domain);

        $this->assertNotContains('demo', $this->names($readiness['without_mailbox']));
        $this->assertGreaterThanOrEqual(1, $readiness['offers_waiting']);
    }

    public function test_offering_twice_does_not_offer_again()
    {
        app(DomainReadiness::class)->offerExisting($this->domain);
        $count = Mailbox::count();

        $again = app(DomainReadiness::class)->offerExisting($this->domain);

        $this->assertSame(0, $again['offered']);
        $this->assertSame($count, Mailbox::count());
    }

    public function test_users_whose_recovery_email_is_on_the_domain_are_flagged()
    {
        app(RecoveryEmailService::class)->set('demo', 'Backup@Example.com');

        $readiness = app(DomainReadiness::class)->for($this->domain);

        $this->assertSame(['demo'], $this->names($readiness['recovery_on_domain']));
        $this->assertSame(1, $readiness['recovery_on_domain_count']);
    }

    public function test_the_offer_action_offers_and_only_works_while_the_domain_is_being_activated()
    {
        $this->post('/settings/domains/example.com/offer_mailboxes')
            ->assertRedirect('/settings/domains/example.com')
            ->assertSessionHas('success');
        $this->assertDatabaseHas('mailboxes', ['username' => 'demo', 'status' => Mailbox::PENDING]);

        Mailbox::query()->delete();
        $this->domain->forceFill(['email_status' => 'active', 'email_enabled' => true])->save();

        $this->post('/settings/domains/example.com/offer_mailboxes')->assertRedirect('/settings/domains/example.com');
        $this->assertDatabaseCount('mailboxes', 0);
    }
}
