<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Ticket;
use Inertia\Testing\AssertableInertia as Assert;

class OrganizationTicketsTest extends TicketsTestCase
{
    public function test_the_feature_flag_hides_tickets(): void
    {
        config(['toggle.flags.tickets' => false]);

        $this->actingAs($this->alice)->get('/support/tickets')->assertNotFound();
    }

    public function test_guests_cannot_see_tickets(): void
    {
        $this->get('/support/tickets')->assertRedirect();
    }

    public function test_a_member_can_create_a_ticket(): void
    {
        $response = $this->actingAs($this->alice)->post('/support/tickets', [
            'subject' => 'Printer on fire',
            'body' => '<p>Help <strong>please</strong></p>',
            'type' => 'bug',
            'impact' => 'outage',
        ]);

        $ticket = Ticket::firstOrFail();
        $response->assertRedirect('/support/tickets/'.$ticket->number);
        $this->assertSame($this->orgA->id, $ticket->organization_id);
        $this->assertSame($this->alice->id, $ticket->requester_id);
        // The customer assignee defaults to the person who opened it
        $this->assertSame($this->alice->id, $ticket->customer_assignee_id);
        $this->assertNull($ticket->support_assignee_id);
        $this->assertSame(TicketStatus::OPEN, $ticket->status);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertSame('created', $ticket->events()->first()->type);
    }

    public function test_creating_through_the_help_desk_modal_returns_json(): void
    {
        $this->actingAs($this->alice)
            ->postJson('/support/tickets', ['subject' => 'Quick question', 'body' => '<p>Hello</p>'])
            ->assertCreated()
            ->assertJsonStructure(['reference', 'url']);
    }

    public function test_subject_and_body_are_required(): void
    {
        $this->actingAs($this->alice)->post('/support/tickets', ['subject' => '', 'body' => ''])
            ->assertSessionHasErrors(['subject', 'body']);

        // A body that is only markup has no content once cleaned
        $this->actingAs($this->alice)->post('/support/tickets', ['subject' => 'x', 'body' => '<script>alert(1)</script>'])
            ->assertSessionHasErrors('body');
    }

    public function test_the_customer_assignee_must_belong_to_the_same_organization(): void
    {
        $this->actingAs($this->alice)->post('/support/tickets', [
            'subject' => 'x', 'body' => '<p>x</p>', 'customer_assignee_id' => $this->carol->id,
        ])->assertSessionHasErrors('customer_assignee_id');

        $this->assertSame(0, Ticket::count());

        $this->actingAs($this->alice)->post('/support/tickets', [
            'subject' => 'x', 'body' => '<p>x</p>', 'customer_assignee_id' => $this->bob->id,
        ])->assertRedirect();

        $this->assertSame($this->bob->id, Ticket::first()->customer_assignee_id);
    }

    public function test_every_member_sees_every_ticket_of_their_organization(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->bob)->get('/support/tickets/'.$ticket->number)->assertOk();
        $this->actingAs($this->bob)->post('/support/tickets/'.$ticket->number.'/messages', ['body' => '<p>Me too</p>'])->assertRedirect();
        $this->assertSame(2, $ticket->messages()->count());
    }

    public function test_another_organization_gets_404_for_every_ticket_route(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $message = $ticket->messages()->first();
        $base = '/support/tickets/'.$ticket->number;

        $this->actingAs($this->carol)->get($base)->assertNotFound();
        $this->actingAs($this->carol)->post($base.'/messages', ['body' => '<p>x</p>'])->assertNotFound();
        $this->actingAs($this->carol)->put($base.'/messages/'.$message->id, ['body' => '<p>x</p>'])->assertNotFound();
        $this->actingAs($this->carol)->put($base, ['subject' => 'hijacked'])->assertNotFound();
        $this->actingAs($this->carol)->post($base.'/close')->assertNotFound();
        $this->actingAs($this->carol)->post($base.'/reopen')->assertNotFound();

        $this->assertSame('Cannot log in', $ticket->fresh()->subject);
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_the_list_shows_only_my_tickets_by_default_and_only_my_organization(): void
    {
        $mine = $this->ticketFor($this->alice, ['subject' => 'Mine']);
        $bobs = $this->ticketFor($this->bob, ['subject' => 'Bobs']);
        $this->ticketFor($this->carol, ['subject' => 'Other org']);

        $this->actingAs($this->alice)->get('/support/tickets')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Organization/Tickets/TicketsList')
                ->where('filters.assigned', 'me')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.number', $mine->number)
                ->where('counts.mine_open', 1)
                ->where('counts.organization_open', 2));

        $this->actingAs($this->alice)->get('/support/tickets?assigned=all')
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 2));

        $this->actingAs($this->alice)->get('/support/tickets?assigned=user:'.$this->bob->id)
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1)->where('tickets.data.0.number', $bobs->number));
    }

    public function test_an_unknown_assigned_filter_falls_back_to_me(): void
    {
        $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->get('/support/tickets?assigned=everyone-else')
            ->assertInertia(fn (Assert $page) => $page->where('filters.assigned', 'me'));
    }

    public function test_the_detail_page_never_contains_internal_notes_or_priority(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->reply($ticket, $this->admin, '<p>SECRET staff-only note</p>', staff: true, internal: true);
        $this->reply($ticket, $this->admin, '<p>Public answer</p>', staff: true);

        $response = $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number);

        $response->assertOk()->assertDontSee('SECRET staff-only note')->assertSee('Public answer');
        $response->assertInertia(fn (Assert $page) => $page
            ->has('messages', 2)
            ->missing('ticket.priority')
            ->missing('ticket.tags'));
    }

    public function test_the_customer_assignee_can_be_changed_by_any_member(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->bob)->put('/support/tickets/'.$ticket->number, ['customer_assignee_id' => $this->bob->id])->assertRedirect();

        $this->assertSame($this->bob->id, $ticket->fresh()->customer_assignee_id);
        $this->assertSame('assigned', $ticket->events()->latest('id')->first()->type);
    }

    public function test_the_customer_assignee_cannot_be_someone_from_another_organization(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number, ['customer_assignee_id' => $this->carol->id])
            ->assertSessionHasErrors('customer_assignee_id');

        $this->assertSame($this->alice->id, $ticket->fresh()->customer_assignee_id);
    }

    public function test_closing_and_reopening(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/close')->assertRedirect();
        $this->assertSame(TicketStatus::CLOSED, $this->status($ticket));

        // A closed ticket cannot be replied to until it is reopened
        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/messages', ['body' => '<p>hello?</p>'])->assertForbidden();

        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/reopen')->assertRedirect();
        $this->assertSame(TicketStatus::OPEN, $this->status($ticket));
    }

    public function test_a_member_can_edit_their_message_until_staff_reply(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $message = $ticket->messages()->first();

        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number.'/messages/'.$message->id, ['body' => '<p>Corrected</p>'])->assertRedirect();

        $message->refresh();
        $this->assertStringContainsString('Corrected', $message->body_html);
        $this->assertNotNull($message->edited_at);
        // The previous text is kept
        $this->assertSame(1, $message->revisions()->count());
        $this->assertStringContainsString('cannot log in', $message->revisions()->first()->body_html);

        $this->reply($ticket, $this->admin, '<p>On it</p>', staff: true);

        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number.'/messages/'.$message->id, ['body' => '<p>Too late</p>'])->assertForbidden();
        $this->assertStringContainsString('Corrected', $message->fresh()->body_html);
    }

    public function test_messages_can_only_be_edited_by_their_author_and_within_the_window(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $message = $ticket->messages()->first();

        $this->actingAs($this->bob)->put('/support/tickets/'.$ticket->number.'/messages/'.$message->id, ['body' => '<p>Not yours</p>'])->assertForbidden();

        $this->travel(config('tickets.edit_window_minutes') + 1)->minutes();

        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number.'/messages/'.$message->id, ['body' => '<p>Late</p>'])->assertForbidden();
    }

    public function test_the_subject_is_editable_only_until_the_first_staff_response(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number, ['subject' => 'Better subject'])->assertRedirect();
        $this->assertSame('Better subject', $ticket->fresh()->subject);

        $this->reply($ticket, $this->admin, '<p>Hi</p>', staff: true);

        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number, ['subject' => 'Changed again'])->assertForbidden();
        $this->assertSame('Better subject', $ticket->fresh()->subject);
    }

    public function test_script_tags_and_handlers_are_stripped_from_messages(): void
    {
        $ticket = $this->ticketFor($this->alice, [
            'body' => '<p onclick="steal()">Hello</p><script>alert(1)</script><a href="javascript:alert(1)">x</a><img src=x onerror=alert(1)>',
        ]);

        $html = $ticket->messages()->first()->body_html;

        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringContainsString('Hello', $html);
    }

    public function test_a_customer_reply_moves_the_ticket_to_waiting_on_support(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->reply($ticket, $this->admin, '<p>Question for you</p>', staff: true);
        $this->assertSame(TicketStatus::PENDING_CUSTOMER, $this->status($ticket));

        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/messages', ['body' => '<p>Answer</p>'])->assertRedirect();

        $this->assertSame(TicketStatus::PENDING_SUPPORT, $this->status($ticket));
    }

    public function test_a_reply_to_a_resolved_ticket_reopens_it(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->changeStatus($ticket, $this->admin, TicketStatus::RESOLVED, asStaff: true);

        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/messages', ['body' => '<p>Still broken</p>'])->assertRedirect();

        $this->assertSame(TicketStatus::PENDING_SUPPORT, $this->status($ticket));
        $this->assertNull($ticket->fresh()->resolved_at);
    }

    public function test_the_modal_button_is_not_a_way_to_post_internal_notes(): void
    {
        $ticket = $this->ticketFor($this->alice);

        // Customers cannot mark a message internal, whatever they send
        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/messages', ['body' => '<p>x</p>', 'internal' => true])->assertRedirect();

        $this->assertSame(0, $ticket->messages()->where('visibility', 'internal')->count());
    }
}
