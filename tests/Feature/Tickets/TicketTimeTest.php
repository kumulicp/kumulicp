<?php

namespace Tests\Feature\Tickets;

use App\Services\Tickets\TicketVersions;
use App\Ticket;
use App\TicketEvent;
use App\TicketTimeEntry;
use Inertia\Testing\AssertableInertia as Assert;

class TicketTimeTest extends TicketsTestCase
{
    private function log(Ticket $ticket, array $data, $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post('/admin/support/tickets/'.$ticket->number.'/time', $data);
    }

    public function test_staff_can_log_time_in_the_ways_people_write_it(): void
    {
        $ticket = $this->ticketFor($this->alice);

        foreach (['45' => 45, '1h30' => 90, '1.5h' => 90, '1:30' => 90, '2 hours 15 minutes' => 135] as $input => $minutes) {
            $this->log($ticket, ['duration' => (string) $input])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($minutes, TicketTimeEntry::latest('id')->first()->minutes, "{$input} was not read as {$minutes} minutes");
        }
    }

    public function test_an_entry_records_who_what_when_and_whether_it_is_billable(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->log($ticket, ['duration' => '1h', 'note' => '  Looked at the logs  ', 'billable' => false, 'worked_at' => '2026-11-01']);

        $entry = TicketTimeEntry::firstOrFail();
        $this->assertSame([$ticket->id, $this->admin->id, 60, 'Looked at the logs', false, '2026-11-01'], [
            $entry->ticket_id, $entry->user_id, $entry->minutes, $entry->note, $entry->billable, $entry->worked_at->toDateString(),
        ]);
        $this->assertTrue(TicketEvent::where('type', 'time_logged')->where('ticket_id', $ticket->id)->value('internal'));
    }

    public function test_nonsense_and_extreme_durations_are_refused(): void
    {
        $ticket = $this->ticketFor($this->alice);

        foreach (['', 'abc', '0', '-5', '1h30x', '25h', '1441'] as $input) {
            $this->log($ticket, ['duration' => $input])->assertSessionHasErrors('duration');
        }

        $this->log($ticket, ['duration' => '1h', 'worked_at' => now()->addDay()->toDateString()])->assertSessionHasErrors('worked_at');
        $this->log($ticket, ['duration' => '1h', 'note' => str_repeat('a', 501)])->assertSessionHasErrors('note');
        $this->assertSame(0, TicketTimeEntry::count());
    }

    public function test_only_staff_can_log_or_remove_time(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->log($ticket, ['duration' => '30']);
        $entry = TicketTimeEntry::firstOrFail();

        $this->log($ticket, ['duration' => '30'], $this->alice)->assertForbidden();
        $this->actingAs($this->alice)->delete('/admin/support/tickets/'.$ticket->number.'/time/'.$entry->id)->assertForbidden();

        $this->assertSame(1, TicketTimeEntry::count());
    }

    public function test_staff_can_remove_an_entry_but_only_through_its_own_ticket(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $other = $this->ticketFor($this->bob);
        $this->log($ticket, ['duration' => '30']);
        $entry = TicketTimeEntry::firstOrFail();

        $this->actingAs($this->admin)->delete('/admin/support/tickets/'.$other->number.'/time/'.$entry->id)->assertNotFound();
        $this->assertSame(1, TicketTimeEntry::count());

        $this->actingAs($this->admin)->delete('/admin/support/tickets/'.$ticket->number.'/time/'.$entry->id)->assertRedirect();
        $this->assertSame(0, TicketTimeEntry::count());
        $this->assertSame(1, TicketEvent::where('type', 'time_deleted')->count());
    }

    public function test_the_staff_page_lists_entries_with_totals(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->log($ticket, ['duration' => '1h', 'billable' => true]);
        $this->log($ticket, ['duration' => '30', 'billable' => false, 'note' => 'Call with customer']);

        $this->actingAs($this->admin)->get('/admin/support/tickets/'.$ticket->number)
            ->assertInertia(fn (Assert $page) => $page
                ->has('time_entries', 2)
                ->where('time_total.total', '1h 30m')
                ->where('time_total.billable', '1h')
                ->where('time_entries.0.duration', '30m')
                ->where('time_entries.0.note', 'Call with customer')
                ->where('time_entries.0.billable', false)
                ->where('time_entries.0.by', $this->admin->name));
    }

    public function test_customers_can_never_see_that_time_was_spent(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->log($ticket, ['duration' => '2h', 'note' => 'Quietly fixed it']);

        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number)
            ->assertInertia(fn (Assert $page) => $page
                ->missing('time_entries')
                ->missing('time_total')
                ->where('timeline', fn ($timeline) => ! $timeline->pluck('type')->contains('time_logged')))
            ->assertDontSee('Quietly fixed it');

        $this->actingAs($this->alice)->getJson('/support/tickets/'.$ticket->number.'/poll?v=stale')->assertOk()->assertJsonMissingPath('time_entries');
    }

    public function test_logging_time_moves_the_ticket_version_so_open_staff_pages_refresh(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $versions = app(TicketVersions::class);
        $before = $versions->ticket($ticket);

        $this->log($ticket, ['duration' => '10']);

        $this->assertNotSame($before, $versions->ticket($ticket));
    }
}
