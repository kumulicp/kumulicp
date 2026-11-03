<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Notifications\Tickets\TicketActivity;
use Illuminate\Support\Facades\Notification;

/**
 * What happens to a ticket when the person looking after it on the customer
 * side is removed or deactivated.
 */
class TicketAssigneeSweepTest extends TicketsTestCase
{
    public function test_it_falls_back_to_the_requester_when_they_can_still_use_the_panel(): void
    {
        $ticket = $this->ticketFor($this->alice, ['customer_assignee_id' => $this->bob->id]);
        $this->bob->delete();
        Notification::fake();

        $changed = $this->service()->sweepRemovedAssignees();

        $this->assertSame(1, $changed);
        $this->assertSame($this->alice->id, $ticket->fresh()->customer_assignee_id);
        $this->assertSame('assignee_removed', $ticket->events()->latest('id')->first()->type);
        Notification::assertSentTo($this->alice, TicketActivity::class, fn ($n) => $n->event === 'assigned');
    }

    public function test_it_leaves_the_ticket_unassigned_when_the_requester_is_gone_too(): void
    {
        $ticket = $this->ticketFor($this->alice, ['customer_assignee_id' => $this->bob->id]);
        $this->bob->forceFill(['is_allowed' => false])->save();
        $this->alice->forceFill(['is_allowed' => false])->save();

        $this->service()->sweepRemovedAssignees();

        $this->assertNull($ticket->fresh()->customer_assignee_id);
    }

    public function test_deactivated_users_count_as_removed(): void
    {
        $ticket = $this->ticketFor($this->alice, ['customer_assignee_id' => $this->bob->id]);
        $this->bob->forceFill(['is_allowed' => false])->save();

        $this->service()->sweepRemovedAssignees();

        $this->assertSame($this->alice->id, $ticket->fresh()->customer_assignee_id);
    }

    public function test_organization_owners_are_told_only_while_the_ticket_is_open(): void
    {
        $this->orgA->primary_contact_id = $this->alice->id;
        $this->orgA->save();

        $open = $this->ticketFor($this->bob);
        $done = $this->ticketFor($this->bob);
        $this->service()->changeStatus($done, $this->admin, TicketStatus::RESOLVED, asStaff: true);
        $this->bob->forceFill(['is_allowed' => false])->save();
        Notification::fake();

        $this->service()->sweepRemovedAssignees();

        Notification::assertSentTo($this->alice, TicketActivity::class, fn ($n) => $n->event === 'assignee_removed' && $n->ticket->is($open));
        Notification::assertNotSentTo($this->alice, TicketActivity::class, fn ($n) => $n->ticket->is($done));
        // The resolved ticket was still tidied up, silently
        $this->assertNotSame($this->bob->id, $done->fresh()->customer_assignee_id);
    }

    public function test_a_removed_support_assignee_is_simply_unassigned(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->assign($ticket, 'support', $this->admin, $this->admin);
        $this->admin->forceFill(['is_allowed' => false])->save();

        $this->service()->sweepRemovedAssignees();

        $this->assertNull($ticket->fresh()->support_assignee_id);
    }

    public function test_a_second_sweep_changes_nothing(): void
    {
        $this->ticketFor($this->alice, ['customer_assignee_id' => $this->bob->id]);
        $this->bob->delete();

        $this->assertSame(1, $this->service()->sweepRemovedAssignees());
        $this->assertSame(0, $this->service()->sweepRemovedAssignees());
    }

    public function test_the_sweep_can_be_limited_to_one_organization(): void
    {
        $this->ticketFor($this->alice, ['customer_assignee_id' => $this->bob->id]);
        $this->ticketFor($this->carol);
        $this->bob->delete();
        $this->carol->delete();

        $this->assertSame(1, $this->service()->sweepRemovedAssignees($this->orgA->id));
    }
}
