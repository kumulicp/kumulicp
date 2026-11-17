<?php

namespace Tests\Feature\Tickets;

/**
 * Each limit counts on its own. (Laravel's numeric throttle keys on the user
 * only, so without a prefix per route the polling of open pages used up the
 * "10 new tickets an hour" allowance and a second ticket got 429.)
 */
class TicketThrottleTest extends TicketsTestCase
{
    private function newTicket(string $subject)
    {
        return $this->actingAs($this->alice)->postJson('/support/tickets', ['subject' => $subject, 'body' => '<p>Help</p>']);
    }

    public function test_polling_does_not_use_up_the_allowance_for_new_tickets(): void
    {
        $ticket = $this->ticketFor($this->alice);

        // An open ticket page and the badge, for a few minutes' worth of tabs
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number.'/poll?v=x')->assertSuccessful();
            $this->actingAs($this->alice)->get('/support/tickets/summary')->assertSuccessful();
        }

        $this->newTicket('First')->assertSuccessful();
        $this->newTicket('Second')->assertSuccessful();
    }

    public function test_creating_tickets_is_still_limited(): void
    {
        foreach (range(1, 10) as $i) {
            $this->newTicket('Ticket '.$i)->assertSuccessful();
        }

        $this->newTicket('One too many')->assertStatus(429);
        // ...without blocking anything else
        $this->actingAs($this->alice)->get('/support/tickets/summary')->assertSuccessful();
    }

    public function test_replying_does_not_count_against_creating(): void
    {
        $ticket = $this->ticketFor($this->alice);

        foreach (range(1, 12) as $i) {
            $this->actingAs($this->alice)->postJson('/support/tickets/'.$ticket->number.'/messages', ['body' => '<p>Reply '.$i.'</p>'])->assertRedirect();
        }

        $this->newTicket('Still allowed')->assertSuccessful();
    }
}
