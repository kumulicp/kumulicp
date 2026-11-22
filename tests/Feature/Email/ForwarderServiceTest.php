<?php

namespace Tests\Feature\Email;

use App\EmailForwarder;
use App\EmailForwarderDestination;
use App\Events\Mailboxes\ForwarderChanged;
use App\Events\Mailboxes\ForwarderRemoved;
use App\Mailbox;
use App\Notifications\ForwarderDestinationConfirmation;
use App\Organization;
use App\OrgDomain;
use App\Services\Email\ForwarderException;
use App\Services\Email\ForwarderService;
use App\Services\OrganizationService;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TestSupports;
use Tests\TestCase;

class ForwarderServiceTest extends TestCase
{
    use RefreshDatabase;

    private ForwarderService $service;

    private Organization $organization;

    private OrgDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        setupAccountManagerDriver('db');
        (new TestSupports)->seed();
        $this->organization = User::where('username', 'demo')->firstOrFail()->organization;
        $this->domain = OrgDomain::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'corp.org',
            'status' => 'active',
            'email_enabled' => true,
            'email_status' => 'active',
        ]);
        $this->service = app(ForwarderService::class);
        Notification::fake();
        Event::fake([ForwarderChanged::class, ForwarderRemoved::class]);

        Mailbox::create(['organization_id' => $this->organization->id, 'org_domain_id' => $this->domain->id, 'local_part' => 'jane', 'address' => 'jane@corp.org', 'status' => Mailbox::ACTIVE])
            ->addresses()->create(['address' => 'j@corp.org']);
    }

    private function refused(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('The change was allowed');
        } catch (ForwarderException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function make(string $name, string $destination): EmailForwarder
    {
        return $this->service->create($this->organization, $this->domain, $name, $destination);
    }

    public function test_a_forwarder_is_made_with_its_first_destination_and_the_mail_server_is_told()
    {
        $forwarder = $this->make('Sales', 'jane@corp.org');

        $this->assertSame('sales@corp.org', $forwarder->email);
        $this->assertSame(['jane@corp.org'], $forwarder->destinations()->pluck('destination')->all());
        $this->assertTrue($forwarder->destinations->first()->isLive());
        Event::assertDispatched(ForwarderChanged::class, fn ($event) => $event->forwarder->is($forwarder));
    }

    public function test_an_address_that_is_taken_cannot_be_a_forwarder()
    {
        foreach (['jane', 'j'] as $name) {
            $this->refused(fn () => $this->make($name, 'bob@gmail.com'), __('organization.email.mailbox.taken', ['email' => $name.'@corp.org']));
        }

        $this->make('sales', 'jane@corp.org');

        $this->refused(fn () => $this->make('sales', 'bob@gmail.com'), __('organization.email.mailbox.taken', ['email' => 'sales@corp.org']));
    }

    public function test_the_domain_and_the_name_have_to_be_allowed()
    {
        $off = OrgDomain::factory()->make(['organization_id' => $this->organization->id, 'name' => 'off.org', 'email_enabled' => false]);
        $other = OrgDomain::factory()->make(['organization_id' => $this->organization->id + 1000, 'name' => 'other.org', 'email_enabled' => true]);

        foreach ([$off, $other] as $domain) {
            $this->refused(fn () => $this->service->create($this->organization, $domain, 'sales', 'jane@corp.org'), __('organization.email.forwarder.domain_not_allowed', ['domain' => $domain->name]));
        }

        $this->refused(fn () => $this->make('not allowed', 'jane@corp.org'), __('messages.rule.email_local_part'));
    }

    public function test_the_plan_limits_how_many_forwarders_there_are()
    {
        $this->organization->plan->updateSettings(['forwarder.max' => 1]);
        $this->organization->plan->save();
        $this->make('sales', 'jane@corp.org');

        $this->refused(fn () => $this->make('info', 'jane@corp.org'), __('organization.email.forwarder.plan_limit', ['count' => 1]));
        $this->assertSame(1, (new OrganizationService($this->organization))->countForwarder());
    }

    public function test_a_destination_has_to_be_an_address_that_is_not_the_forwarder_itself()
    {
        $this->refused(fn () => $this->make('sales', 'not an address'), __('organization.email.forwarder.invalid', ['email' => 'not an address']));
        $this->refused(fn () => $this->make('sales', 'SALES@corp.org'), __('organization.email.forwarder.itself'));

        $forwarder = $this->make('sales', 'jane@corp.org');

        $this->refused(fn () => $this->service->addDestination($forwarder, 'Sales@Corp.org'), __('organization.email.forwarder.itself'));
        $this->refused(fn () => $this->service->addDestination($forwarder, 'JANE@corp.org'), __('organization.email.forwarder.duplicate', ['email' => 'jane@corp.org']));
    }

    public function test_a_destination_in_the_organization_has_to_exist_and_be_in_use()
    {
        Mailbox::create(['organization_id' => $this->organization->id, 'local_part' => 'offered', 'address' => 'offered@corp.org', 'status' => Mailbox::PENDING]);

        foreach (['nobody@corp.org', 'offered@corp.org'] as $missing) {
            $this->refused(fn () => $this->make('sales', $missing), __('organization.email.forwarder.internal_missing', ['email' => $missing]));
        }

        $this->assertSame(0, EmailForwarder::count());
        $this->make('sales', 'j@corp.org');
        $this->assertSame(1, EmailForwarder::count());
    }

    public function test_the_number_of_destinations_is_limited()
    {
        config(['email.max_forwarder_destinations' => 2]);
        $forwarder = $this->make('sales', 'jane@corp.org');
        $this->service->addDestination($forwarder, 'bob@gmail.com');

        $this->refused(fn () => $this->service->addDestination($forwarder, 'amy@gmail.com'), __('organization.email.forwarder.destination_limit', ['count' => 2]));
    }

    public function test_forwarders_cannot_lead_back_to_where_they_started()
    {
        $sales = $this->make('sales', 'jane@corp.org');
        $info = $this->make('info', 'sales@corp.org');

        // sales -> info -> sales
        $this->refused(fn () => $this->service->addDestination($sales, 'info@corp.org'), __('organization.email.forwarder.loop'));
        $this->assertSame(['jane@corp.org'], $sales->destinations()->pluck('destination')->all());

        // A chain that does not come back is fine
        $team = $this->make('team', 'info@corp.org');
        $this->assertSame(['info@corp.org'], $team->destinations()->pluck('destination')->all());
    }

    public function test_a_long_chain_of_forwarders_is_refused()
    {
        $previous = $this->make('f1', 'jane@corp.org');

        for ($i = 2; $i <= 5; $i++) {
            $previous = $this->make("f$i", $previous->email);
        }

        // f6 -> f5 -> f4 -> f3 -> f2 -> f1 is five forwarders deep; one more is too many
        $f6 = $this->make('f6', 'f5@corp.org');

        $this->refused(fn () => $this->make('f7', 'f6@corp.org'), __('organization.email.forwarder.too_deep', ['count' => 5]));
        $this->assertNotNull($f6);
    }

    public function test_an_outside_destination_is_held_until_its_owner_confirms_it()
    {
        $forwarder = $this->make('sales', 'jane@corp.org');

        $row = $this->service->addDestination($forwarder, 'Bob@Gmail.com');

        $this->assertSame(EmailForwarderDestination::EXTERNAL, $row->kind);
        $this->assertFalse($row->isLive());
        $this->assertEqualsCanonicalizing(['jane@corp.org'], $forwarder->liveDestinations()->pluck('destination')->all());
        Notification::assertSentOnDemand(ForwarderDestinationConfirmation::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'bob@gmail.com' && $notification->forwarder_address === 'sales@corp.org');
    }

    private function sentToken(): string
    {
        $url = null;
        Notification::assertSentOnDemand(ForwarderDestinationConfirmation::class, function ($notification) use (&$url) {
            $url = $notification->url;

            return true;
        });

        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_opening_the_link_starts_forwarding_once_and_only_in_time()
    {
        $forwarder = $this->make('sales', 'jane@corp.org');
        $row = $this->service->addDestination($forwarder, 'bob@gmail.com');
        $token = $this->sentToken();

        $this->assertFalse($this->service->confirm($row->id, 'not-the-token'));
        $this->assertFalse($row->fresh()->isLive());

        $this->assertTrue($this->service->confirm($row->id, $token));
        $this->assertTrue($row->fresh()->isLive());
        $this->assertSame(EmailForwarder::PENDING, $forwarder->fresh()->status);
        $this->assertFalse($this->service->confirm($row->id, $token), 'a link works once');
    }

    public function test_a_link_that_is_too_old_is_refused_and_a_new_one_can_be_sent()
    {
        $forwarder = $this->make('sales', 'jane@corp.org');
        $row = $this->service->addDestination($forwarder, 'bob@gmail.com');
        $token = $this->sentToken();

        $this->travel((int) config('email.forwarder_confirm_hours') + 1)->hours();

        $this->assertFalse($this->service->confirm($row->id, $token));

        $this->service->resend($forwarder, 'bob@gmail.com');
        Notification::assertSentOnDemandTimes(ForwarderDestinationConfirmation::class, 2);
        $this->refused(fn () => $this->service->resend($forwarder, 'jane@corp.org'), __('organization.email.forwarder.nothing_to_confirm', ['email' => 'jane@corp.org']));
    }

    public function test_a_destination_can_be_removed_but_not_the_last_one()
    {
        $forwarder = $this->make('sales', 'jane@corp.org');
        $this->service->addDestination($forwarder, 'bob@gmail.com');

        $this->service->removeDestination($forwarder, 'BOB@gmail.com');

        $this->assertSame(['jane@corp.org'], $forwarder->destinations()->pluck('destination')->all());
        $this->refused(fn () => $this->service->removeDestination($forwarder, 'jane@corp.org'), __('organization.email.forwarder.last_destination'));
        $this->refused(fn () => $this->service->removeDestination($forwarder, 'amy@gmail.com'), __('organization.email.forwarder.destination_not_found', ['email' => 'amy@gmail.com']));
    }

    public function test_removing_a_forwarder_frees_its_address_and_tells_the_mail_server()
    {
        $forwarder = $this->make('sales', 'jane@corp.org');

        $this->service->remove($forwarder);

        $this->assertDatabaseCount('email_forwarders', 0);
        $this->assertDatabaseMissing('mailbox_addresses', ['address' => 'sales@corp.org']);
        Event::assertDispatched(ForwarderRemoved::class, fn ($event) => $event->address === 'sales@corp.org' && $event->organization_id === $this->organization->id);
        $this->make('sales', 'jane@corp.org');
    }

    public function test_what_the_mail_server_reports_is_kept()
    {
        $forwarder = $this->make('sales', 'jane@corp.org');

        $this->service->applied($forwarder, false, 'connection refused');
        $this->assertSame([EmailForwarder::FAILED, 'connection refused'], [$forwarder->fresh()->status, $forwarder->fresh()->last_error]);

        $this->service->applied($forwarder, true);
        $this->assertSame([EmailForwarder::ACTIVE, null], [$forwarder->fresh()->status, $forwarder->fresh()->last_error]);
        $this->assertNotNull($forwarder->fresh()->applied_at);
    }

    public function test_the_confirmation_link_works_without_a_session_and_says_whether_it_worked()
    {
        $forwarder = $this->make('sales', 'jane@corp.org');
        $row = $this->service->addDestination($forwarder, 'bob@gmail.com');
        $url = null;
        Notification::assertSentOnDemand(ForwarderDestinationConfirmation::class, function ($notification) use (&$url) {
            $url = $notification->url;

            return true;
        });

        $this->get($url)->assertOk()->assertSee('data-confirmed="true"', false);
        $this->assertTrue($row->fresh()->isLive());

        $this->get($url)->assertOk()->assertSee('data-confirmed="false"', false);
        $this->get(preg_replace('/signature=[^&]+/', 'signature=bad', $url))->assertForbidden();
    }
}
