<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Notifications\Tickets\TicketActivity;
use Illuminate\Support\Facades\Notification;

class TicketNotificationsTest extends TicketsTestCase
{
    private function sentTo($user, ?string $event = null): bool
    {
        return Notification::sent($user, TicketActivity::class, fn (TicketActivity $n) => $event === null || $n->event === $event)->isNotEmpty();
    }

    public function test_a_new_ticket_confirms_to_the_customer_side_and_tells_support(): void
    {
        $ticket = $this->ticketFor($this->alice, ['customer_assignee_id' => $this->bob->id]);

        $this->assertTrue($this->sentTo($this->alice, 'created'));
        $this->assertTrue($this->sentTo($this->bob, 'created'));
        // Unassigned: the team is told in the panel
        $this->assertTrue($this->sentTo($this->admin, 'created'));
        $this->assertFalse($this->sentTo($this->carol));
    }

    public function test_a_public_staff_reply_reaches_the_customer_side_but_not_other_organizations(): void
    {
        $ticket = $this->ticketFor($this->alice, ['customer_assignee_id' => $this->bob->id]);
        Notification::fake();

        $this->reply($ticket, $this->admin, '<p>Fixed</p>', staff: true);

        $this->assertTrue($this->sentTo($this->alice, 'reply'));
        $this->assertTrue($this->sentTo($this->bob, 'reply'));
        $this->assertFalse($this->sentTo($this->carol));
    }

    public function test_internal_notes_never_reach_the_customer_side(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->assign($ticket, 'support', $this->admin, $this->admin);
        Notification::fake();

        $this->reply($ticket, $this->admin, '<p>Private</p>', staff: true, internal: true);

        $this->assertFalse($this->sentTo($this->alice));
        $this->assertFalse($this->sentTo($this->bob));
    }

    public function test_a_customer_reply_goes_to_the_support_assignee_not_to_the_author(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->assign($ticket, 'support', $this->admin, $this->admin);
        Notification::fake();

        $this->reply($ticket, $this->alice, '<p>Any news?</p>');

        $this->assertTrue($this->sentTo($this->admin, 'reply'));
        $this->assertFalse($this->sentTo($this->alice));
    }

    public function test_assignment_notifies_the_new_assignee_but_not_the_person_who_assigned(): void
    {
        $ticket = $this->ticketFor($this->alice);
        Notification::fake();

        $this->service()->assign($ticket, 'customer', $this->bob, $this->alice);
        $this->assertTrue($this->sentTo($this->bob, 'assigned'));

        Notification::fake();
        $this->service()->assign($ticket, 'customer', $this->alice, $this->alice);
        $this->assertFalse($this->sentTo($this->alice));
    }

    public function test_the_notification_text_never_includes_html(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->assign($ticket, 'support', $this->admin, $this->admin);
        Notification::fake();

        $this->reply($ticket, $this->alice, '<p>Hello <strong>there</strong></p>');

        Notification::assertSentTo($this->admin, TicketActivity::class, function (TicketActivity $notification) {
            return $notification->context['excerpt'] === 'Hello there';
        });
    }

    public function test_status_changes_notify_the_customer_side(): void
    {
        $ticket = $this->ticketFor($this->alice);
        Notification::fake();

        $this->service()->changeStatus($ticket, $this->admin, TicketStatus::RESOLVED, asStaff: true);

        $this->assertTrue($this->sentTo($this->alice, 'status'));
    }
}
