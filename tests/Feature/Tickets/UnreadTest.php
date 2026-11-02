<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Services\Tickets\TicketUnread;
use App\TicketRead;
use Inertia\Testing\AssertableInertia as Assert;

class UnreadTest extends TicketsTestCase
{
    private function unreadInList(string $url, $user, int $number): bool
    {
        $found = null;

        $this->actingAs($user)->get($url)->assertInertia(fn (Assert $page) => $page->where('tickets.data', function ($tickets) use (&$found, $number) {
            $found = $tickets->firstWhere('number', $number)['unread'] ?? null;

            return true;
        }));

        return (bool) $found;
    }

    public function test_your_own_messages_are_never_unread(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->assertFalse($this->unreadInList('/support/tickets', $this->alice, $ticket->number));
        $this->assertSame(0, app(TicketUnread::class)->countFor($this->alice, false));
    }

    public function test_a_reply_from_someone_else_is_unread_until_the_ticket_is_opened(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->reply($ticket, $this->admin, '<p>Hello</p>', staff: true);

        $this->assertTrue($this->unreadInList('/support/tickets', $this->alice, $ticket->number));

        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number)->assertOk();

        $this->assertFalse($this->unreadInList('/support/tickets', $this->alice, $ticket->number));
    }

    public function test_a_colleagues_reply_is_unread_too(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->reply($ticket, $this->bob, '<p>I looked into it</p>');

        $this->assertTrue($this->unreadInList('/support/tickets?assigned=all', $this->alice, $ticket->number));
    }

    public function test_internal_notes_do_not_make_a_ticket_unread_for_customers(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->reply($ticket, $this->admin, '<p>Private</p>', staff: true, internal: true);

        $this->assertFalse($this->unreadInList('/support/tickets', $this->alice, $ticket->number));
    }

    public function test_staff_see_customer_replies_and_internal_notes_as_unread(): void
    {
        $second = $this->staffMember('second');
        $ticket = $this->ticketFor($this->alice);
        $this->service()->assign($ticket, 'support', $this->admin, $this->admin);

        // The customer's first message is unread for the assignee
        $this->assertTrue($this->unreadInList('/admin/support/tickets?assigned=all', $this->admin, $ticket->number));
        $this->actingAs($this->admin)->get('/admin/support/tickets/'.$ticket->number)->assertOk();
        $this->assertFalse($this->unreadInList('/admin/support/tickets?assigned=all', $this->admin, $ticket->number));

        // A colleague's internal note is unread for them
        $this->reply($ticket, $second, '<p>FYI</p>', staff: true, internal: true);
        $this->assertTrue($this->unreadInList('/admin/support/tickets?assigned=all', $this->admin, $ticket->number));
    }

    public function test_writing_a_reply_marks_the_ticket_read_for_the_author(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->reply($ticket, $this->admin, '<p>Question</p>', staff: true);
        $this->assertTrue($this->unreadInList('/support/tickets', $this->alice, $ticket->number));

        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/messages', ['body' => '<p>Answer</p>']);

        $this->assertFalse($this->unreadInList('/support/tickets', $this->alice, $ticket->number));
    }

    public function test_reading_never_moves_backwards(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $reply = $this->reply($ticket, $this->admin, '<p>Hello</p>', staff: true);
        $unread = app(TicketUnread::class);

        $unread->markReadUpTo($ticket, $this->alice, $reply->id);
        // A stale tab reports an older position
        $unread->markReadUpTo($ticket, $this->alice, $reply->id - 1);

        $this->assertSame($reply->id, (int) TicketRead::where('ticket_id', $ticket->id)->where('user_id', $this->alice->id)->value('last_read_message_id'));
    }

    public function test_one_persons_reading_does_not_affect_another(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->reply($ticket, $this->admin, '<p>Hello</p>', staff: true);

        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number);

        $this->assertTrue($this->unreadInList('/support/tickets?assigned=all', $this->bob, $ticket->number));
    }

    public function test_closed_tickets_are_not_in_the_badge(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->reply($ticket, $this->admin, '<p>Done</p>', staff: true);
        $this->service()->changeStatus($ticket, $this->admin, TicketStatus::CLOSED, true);

        $this->assertSame(0, app(TicketUnread::class)->countFor($this->alice, false));
    }

    public function test_a_watcher_counts_the_ticket_in_their_badge(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->syncWatchers($ticket, [$this->bob->id], $this->alice, false);
        $this->reply($ticket, $this->admin, '<p>News</p>', staff: true);

        $this->assertSame(1, app(TicketUnread::class)->countFor($this->bob, false));
    }

    public function test_another_organizations_tickets_never_count_for_you(): void
    {
        $this->ticketFor($this->carol);

        $this->assertSame(0, app(TicketUnread::class)->countFor($this->alice, false));
    }
}
