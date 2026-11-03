<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Services\Tickets\TicketSla;
use App\TicketCategory;
use App\TicketEvent;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

class TicketSlaTest extends TicketsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function sla(): TicketSla
    {
        return app(TicketSla::class);
    }

    public function test_without_a_policy_there_are_no_clocks(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->assertNull($ticket->sla_policy_id);
        $this->assertNull($ticket->sla_first_response_due_at);
        $this->assertSame(['first_response' => 'none', 'resolution' => 'none'], $this->sla()->state($ticket));
    }

    public function test_the_default_policy_sets_due_times_for_the_priority(): void
    {
        $default = $this->slaPolicy('Standard', $this->standardTargets(), null, true);

        $ticket = $this->ticketFor($this->alice);

        $this->assertSame($default->id, $ticket->sla_policy_id);
        $this->assertSame('11:00', $ticket->sla_first_response_due_at->format('H:i'));
        $this->assertSame('18:00', $ticket->sla_resolution_due_at->format('H:i'));
    }

    public function test_a_change_of_priority_recomputes_from_when_the_ticket_was_opened(): void
    {
        $this->slaPolicy('Standard', $this->standardTargets(), null, true);
        $ticket = $this->ticketFor($this->alice);

        Carbon::setTestNow(Carbon::parse('2026-11-02 10:20:00', 'UTC'));
        $this->service()->changePriority($ticket, $this->admin, TicketPriority::HIGH);

        $ticket->refresh();
        $this->assertSame('10:30', $ticket->sla_first_response_due_at->format('H:i'));
        $this->assertSame('14:00', $ticket->sla_resolution_due_at->format('H:i'));
    }

    public function test_the_category_policy_wins_unless_it_is_inactive(): void
    {
        $default = $this->slaPolicy('Standard', $this->standardTargets(), null, true);
        $old = $this->slaPolicy('Old', $this->standardTargets(), null, false, false);
        $vip = $this->slaPolicy('VIP', ['normal' => ['first_response' => 10, 'resolution' => 60]]);

        $category = new TicketCategory(['name' => 'VIP', 'slug' => 'vip']);
        $category->active = true;
        $category->sla_policy_id = $old->id;
        $category->save();

        $this->assertSame($default->id, $this->ticketFor($this->alice, ['category_id' => $category->id])->sla_policy_id);

        $category->sla_policy_id = $vip->id;
        $category->save();

        $ticket = $this->ticketFor($this->alice, ['category_id' => $category->id]);
        $this->assertSame($vip->id, $ticket->sla_policy_id);
        $this->assertSame('10:10', $ticket->sla_first_response_due_at->format('H:i'));
    }

    public function test_a_priority_with_no_target_has_no_clock(): void
    {
        $this->slaPolicy('Urgent only', ['urgent' => ['first_response' => 15, 'resolution' => 120]], null, true);

        $ticket = $this->ticketFor($this->alice);

        $this->assertNull($ticket->sla_first_response_due_at);
        $this->assertNull($ticket->sla_resolution_due_at);
    }

    public function test_the_state_of_a_clock_over_time(): void
    {
        $this->slaPolicy('Standard', $this->standardTargets(), null, true);
        $ticket = $this->ticketFor($this->alice, ['priority' => 'high']);
        $state = fn (string $time) => $this->sla()->state($ticket, Carbon::parse("2026-11-02 $time", 'UTC'))['first_response'];

        $this->assertSame('ok', $state('10:10'));
        // Under 20% of the 30 minutes is left
        $this->assertSame('warning', $state('10:25'));
        $this->assertSame('breached', $state('10:31'));

        $ticket->first_response_at = Carbon::parse('2026-11-02 10:20', 'UTC');
        $this->assertSame('met', $this->sla()->state($ticket, Carbon::parse('2026-11-02 12:00', 'UTC'))['first_response']);

        $ticket->first_response_at = Carbon::parse('2026-11-02 10:45', 'UTC');
        $this->assertSame('missed', $this->sla()->state($ticket, Carbon::parse('2026-11-02 12:00', 'UTC'))['first_response']);
    }

    public function test_waiting_on_the_customer_stops_the_resolution_clock_and_resuming_gives_the_time_back(): void
    {
        $hours = ['timezone' => 'UTC', 'days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00', 'holidays' => []];
        $this->slaPolicy('Office', ['normal' => ['first_response' => 60, 'resolution' => 480]], $hours, true);

        $ticket = $this->ticketFor($this->alice);
        $this->assertSame('Mon 11:00', $ticket->sla_first_response_due_at->format('D H:i'));
        $this->assertSame('Tue 10:00', $ticket->sla_resolution_due_at->format('D H:i'));

        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:00', 'UTC'));
        $this->service()->changeStatus($ticket, $this->admin, TicketStatus::PENDING_CUSTOMER, asStaff: true);
        $ticket->refresh();
        $this->assertSame('12:00', $ticket->sla_paused_at->format('H:i'));
        $this->assertSame('paused', $this->sla()->state($ticket)['resolution']);

        // Paused from Monday 12:00 to Tuesday 11:00 is 300 + 120 working minutes
        Carbon::setTestNow(Carbon::parse('2026-11-03 11:00:00', 'UTC'));
        $this->service()->changeStatus($ticket, $this->admin, TicketStatus::OPEN, asStaff: true);
        $ticket->refresh();
        $this->assertNull($ticket->sla_paused_at);
        $this->assertSame(420, $ticket->sla_paused_minutes);
        $this->assertSame('Tue 17:00', $ticket->sla_resolution_due_at->format('D H:i'));

        // A later recompute keeps what was given back
        $this->service()->changePriority($ticket, $this->admin, TicketPriority::NORMAL);
        $this->assertSame('Tue 17:00', $ticket->fresh()->sla_resolution_due_at->format('D H:i'));
    }

    public function test_the_sweep_flags_a_warning_and_a_breach_once_each_as_staff_only_events(): void
    {
        $this->slaPolicy('Standard', $this->standardTargets(), null, true);

        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00', 'UTC'));
        $late = $this->ticketFor($this->alice, ['subject' => 'Late']);        // due 10:00
        Carbon::setTestNow(Carbon::parse('2026-11-02 11:01:00', 'UTC'));
        $close = $this->ticketFor($this->bob, ['subject' => 'Close']);        // due 12:01
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00', 'UTC'));
        $answered = $this->ticketFor($this->alice, ['subject' => 'Answered']);
        $answered->forceFill(['first_response_at' => Carbon::parse('2026-11-02 09:20', 'UTC'), 'status' => TicketStatus::RESOLVED])->save();

        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:00', 'UTC'));

        $this->assertSame(['warned' => 1, 'breached' => 1], $this->service()->sweepSla());

        $this->assertSame(1, TicketEvent::where('ticket_id', $close->id)->where('type', 'sla_warning')->count());
        $this->assertSame(1, TicketEvent::where('ticket_id', $late->id)->where('type', 'sla_breached')->count());
        $this->assertSame(0, TicketEvent::where('ticket_id', $answered->id)->whereIn('type', ['sla_warning', 'sla_breached'])->count());
        // What the customer sees never mentions our deadlines
        $this->assertSame(0, TicketEvent::whereIn('type', ['sla_warning', 'sla_breached'])->where('internal', false)->count());

        // Nothing is flagged twice
        $this->assertSame(['warned' => 0, 'breached' => 0], $this->service()->sweepSla());

        // The warned ticket breaches later
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:30:00', 'UTC'));
        $this->assertSame(['warned' => 0, 'breached' => 1], $this->service()->sweepSla());
    }

    public function test_customers_do_not_see_deadlines(): void
    {
        $this->slaPolicy('Standard', $this->standardTargets(), null, true);
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Organization/Tickets/TicketShow')
                ->missing('ticket.sla')
                ->missing('ticket.sla_first_response_due_at'));
    }
}
