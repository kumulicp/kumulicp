<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Organization;
use App\Services\Tickets\TicketService;
use App\Ticket;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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
