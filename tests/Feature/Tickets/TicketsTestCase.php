<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Organization;
use App\Services\Tickets\Integrations\HostResolver;
use App\Services\Tickets\TicketService;
use App\Ticket;
use App\TicketIntegration;
use App\TicketSlaPolicy;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeHostResolver;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * Shared setup for the ticket tests: the seeded admin organization (id 1, staff
 * user id 1), a second customer organization with two members, and the
 * `tickets` feature switched on.
 */
abstract class TicketsTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Organization $orgA;

    protected Organization $orgB;

    /** Two members of organization A */
    protected User $alice;

    protected User $bob;

    /** A member of organization B */
    protected User $carol;

    protected function setUp(): void
    {
        parent::setUp();

        (new TestSupports)->seed();
        config(['toggle.flags.tickets' => true]);
        Notification::fake();
        Storage::fake('tickets');
        // Jobs only run when a test runs them, and nothing may reach the real network
        Queue::fake();
        Http::preventStrayRequests();
        // No test ever does a real DNS lookup
        $this->app->instance(HostResolver::class, new FakeHostResolver);

        $this->admin = User::find(1);
        $this->orgA = Organization::factory()->create(['slug' => 'acme', 'name' => 'Acme', 'plan_id' => 1]);
        $this->orgB = Organization::factory()->create(['slug' => 'globex', 'name' => 'Globex', 'plan_id' => 1]);

        $this->alice = $this->member($this->orgA, 'alice');
        $this->bob = $this->member($this->orgA, 'bob');
        $this->carol = $this->member($this->orgB, 'carol');
    }

    protected function member(Organization $organization, string $username): User
    {
        return User::factory()->create([
            'organization_id' => $organization->id,
            'username' => $username,
            'name' => ucfirst($username),
            'email' => $username.'@example.com',
            'is_allowed' => true,
        ]);
    }

    /**
     * Another member of the support staff (control panel admin) in the admin organization.
     */
    protected function staffMember(string $username): User
    {
        $user = $this->member(Organization::find(1), $username);
        $user->assignRole('control_panel_admin');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $attributes  Columns to set: options, filters, events, enabled...
     */
    protected function integration(string $driver, array $config, array $attributes = []): TicketIntegration
    {
        $integration = new TicketIntegration(['driver' => $driver, 'name' => ucfirst($driver)]);
        $integration->config = $config;
        $integration->enabled = true;

        foreach ($attributes as $column => $value) {
            $integration->{$column} = $value;
        }

        $integration->save();

        return $integration;
    }

    protected function webhook(array $events = ['ticket.created', 'message.created', 'status.changed', 'assigned', 'time.logged'], array $attributes = []): TicketIntegration
    {
        return $this->integration('generic_webhook', ['url' => 'https://hooks.example.test/in', 'secret' => 'sekrit'], array_merge(['events' => $events], $attributes));
    }

    protected function github(array $options = ['auto_create' => true], array $attributes = []): TicketIntegration
    {
        return $this->integration('github', ['token' => 'ghp_secret', 'repository' => 'acme/platform', 'labels' => 'support'], array_merge(['options' => $options], $attributes));
    }

    /**
     * @param  array<string, array{first_response?: int, resolution?: int}>  $targets  Minutes, per priority
     * @param  array<string, mixed>|null  $hours  Business hours, or null to count around the clock
     */
    protected function slaPolicy(string $name, array $targets, ?array $hours = null, bool $default = false, bool $active = true): TicketSlaPolicy
    {
        $policy = new TicketSlaPolicy(['name' => $name]);
        $policy->targets = $targets;
        $policy->business_hours = $hours;
        $policy->is_default = $default;
        $policy->active = $active;
        $policy->save();

        return $policy;
    }

    /** First response in an hour, resolution in a working day, tighter for the urgent */
    protected function standardTargets(): array
    {
        return [
            'low' => ['first_response' => 480, 'resolution' => 4320],
            'normal' => ['first_response' => 60, 'resolution' => 480],
            'high' => ['first_response' => 30, 'resolution' => 240],
            'urgent' => ['first_response' => 15, 'resolution' => 120],
        ];
    }

    protected function service(): TicketService
    {
        return app(TicketService::class);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function ticketFor(User $requester, array $data = []): Ticket
    {
        return $this->service()->create($requester, array_merge([
            'subject' => 'Cannot log in',
            'body' => '<p>I cannot log in to my app.</p>',
        ], $data));
    }

    /**
     * @param  list<int>  $attachmentIds
     */
    protected function reply(Ticket $ticket, User $author, string $body = '<p>Reply</p>', bool $staff = false, bool $internal = false, array $attachmentIds = [])
    {
        return $this->service()->reply($ticket, $author, $body, $staff, $internal, $attachmentIds);
    }

    protected function status(Ticket $ticket): TicketStatus
    {
        return $ticket->fresh()->status;
    }
}
