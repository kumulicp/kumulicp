<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Ticket;
use App\TicketCategory;
use Inertia\Testing\AssertableInertia as Assert;

class AdminTicketsTest extends TicketsTestCase
{
    public function test_non_admins_cannot_use_the_admin_side(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->get('/admin/support/tickets')->assertForbidden();
        $this->actingAs($this->alice)->get('/admin/support/tickets/'.$ticket->number)->assertForbidden();
        $this->actingAs($this->alice)->post('/admin/support/tickets/'.$ticket->number, ['priority' => 'urgent'])->assertForbidden();
        $this->actingAs($this->alice)->post('/admin/support/tickets/'.$ticket->number.'/messages', ['body' => '<p>x</p>'])->assertForbidden();
        $this->actingAs($this->alice)->post('/admin/support/categories', ['name' => 'Billing'])->assertForbidden();

        $this->assertSame('normal', $ticket->fresh()->priority->value);
    }

    public function test_the_feature_flag_hides_the_admin_side(): void
    {
        config(['toggle.flags.tickets' => false]);

        $this->actingAs($this->admin)->get('/admin/support/tickets')->assertNotFound();
    }

    public function test_the_inbox_opens_on_my_tickets(): void
    {
        $unassigned = $this->ticketFor($this->alice, ['subject' => 'Unassigned one']);
        $mine = $this->ticketFor($this->bob, ['subject' => 'Mine']);
        $this->service()->assign($mine, 'support', $this->admin, $this->admin);

        $this->actingAs($this->admin)->get('/admin/support/tickets')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tickets/TicketsList')
                ->where('filters.assigned', 'me')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.number', $mine->number)
                ->where('counts.mine_open', 1)
                ->where('counts.unassigned_open', 1)
                ->where('counts.open', 2));

        $this->actingAs($this->admin)->get('/admin/support/tickets?assigned=unassigned')
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1)->where('tickets.data.0.number', $unassigned->number));

        // Staff see tickets from every organization
        $this->ticketFor($this->carol);
        $this->actingAs($this->admin)->get('/admin/support/tickets?assigned=all')
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 3));
    }

    public function test_staff_see_internal_notes_and_can_add_them(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number.'/messages', [
            'body' => '<p>Customer is on the old plan</p>', 'internal' => true,
        ])->assertRedirect();

        $note = $ticket->messages()->where('visibility', 'internal')->firstOrFail();
        $this->assertSame('staff', $note->author_kind);
        // A note changes nothing for the customer
        $this->assertSame(TicketStatus::OPEN, $this->status($ticket));
        $this->assertNull($ticket->fresh()->first_response_at);

        $this->actingAs($this->admin)->get('/admin/support/tickets/'.$ticket->number)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tickets/TicketShow')
                ->has('messages', 2)
                ->where('messages.1.internal', true)
                ->has('context.apps'));
    }

    public function test_a_public_staff_reply_records_first_response_and_waits_on_the_customer(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number.'/messages', ['body' => '<p>Hello Alice</p>'])->assertRedirect();

        $ticket->refresh();
        $this->assertNotNull($ticket->first_response_at);
        $this->assertSame(TicketStatus::PENDING_CUSTOMER, $ticket->status);
    }

    public function test_staff_can_change_status_priority_category_and_tags(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $category = TicketCategory::create(['name' => 'Billing', 'slug' => 'billing', 'active' => true]);

        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number, [
            'lock_version' => $ticket->lock_version,
            'status' => 'on_hold',
            'priority' => 'urgent',
            'category_id' => $category->id,
            'tags' => ['Refund', 'refund', ' vip '],
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame(TicketStatus::ON_HOLD, $ticket->status);
        $this->assertSame('urgent', $ticket->priority->value);
        $this->assertSame($category->id, $ticket->category_id);
        $this->assertEqualsCanonicalizing(['refund', 'vip'], $ticket->tags()->pluck('name')->all());
        $this->assertSame(1, $ticket->lock_version);
    }

    public function test_internal_events_are_not_in_the_customer_timeline(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->changePriority($ticket, $this->admin, TicketPriority::URGENT);
        $this->service()->syncTags($ticket, ['vip'], $this->admin);
        $this->service()->changeStatus($ticket, $this->admin, TicketStatus::ON_HOLD, asStaff: true);

        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number)
            ->assertInertia(fn (Assert $page) => $page->where('timeline', function ($timeline) {
                $types = $timeline->pluck('type');

                return $types->contains('status_changed')
                    && ! $types->contains('priority_changed')
                    && ! $types->contains('tags_changed');
            }));
    }

    public function test_a_stale_edit_is_rejected(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number, ['lock_version' => 0, 'priority' => 'high'])->assertRedirect();
        // Someone else still holds version 0
        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number, ['lock_version' => 0, 'priority' => 'low'])
            ->assertSessionHasErrors('lock_version');

        $this->assertSame('high', $ticket->fresh()->priority->value);
    }

    public function test_the_support_assignee_must_be_staff(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number, ['support_assignee_id' => $this->bob->id])
            ->assertSessionHasErrors('support_assignee_id');
        $this->assertNull($ticket->fresh()->support_assignee_id);

        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number, ['support_assignee_id' => $this->admin->id])->assertRedirect();
        $this->assertSame($this->admin->id, $ticket->fresh()->support_assignee_id);
    }

    public function test_staff_cannot_assign_the_customer_side_to_another_organization(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number, ['customer_assignee_id' => $this->carol->id])
            ->assertSessionHasErrors('customer_assignee_id');
        $this->assertSame($this->alice->id, $ticket->fresh()->customer_assignee_id);
    }

    public function test_both_assignees_are_shown_to_the_customer_but_only_the_support_name(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->assign($ticket, 'support', $this->admin, $this->admin);

        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number)
            ->assertInertia(fn (Assert $page) => $page
                ->where('ticket.customer_assignee.name', 'Alice')
                ->where('ticket.support_assignee.name', $this->admin->name)
                ->missing('ticket.support_assignee.id')
                ->missing('ticket.support_assignee.email'));
    }

    public function test_categories_are_archived_not_deleted(): void
    {
        $this->actingAs($this->admin)->post('/admin/support/categories', ['name' => 'Billing'])->assertRedirect();
        $category = TicketCategory::firstOrFail();
        $ticket = $this->ticketFor($this->alice, ['category_id' => $category->id]);

        $this->actingAs($this->admin)->delete('/admin/support/categories/'.$category->id)->assertRedirect();

        $this->assertFalse($category->fresh()->active);
        $this->assertSame($category->id, $ticket->fresh()->category_id);
    }

    public function test_a_category_default_assignee_is_applied_to_new_tickets(): void
    {
        $category = TicketCategory::create(['name' => 'Billing', 'slug' => 'billing', 'active' => true, 'default_support_assignee_id' => $this->admin->id]);

        $ticket = $this->ticketFor($this->alice, ['category_id' => $category->id]);

        $this->assertSame($this->admin->id, $ticket->support_assignee_id);
    }

    public function test_ticket_numbers_are_sequential(): void
    {
        $first = $this->ticketFor($this->alice);
        $second = $this->ticketFor($this->carol);

        $this->assertSame($first->number + 1, $second->number);
        $this->assertSame(config('tickets.prefix').'-'.$second->number, $second->reference);
    }

    public function test_tickets_are_searchable_by_subject_and_number(): void
    {
        $wanted = $this->ticketFor($this->alice, ['subject' => 'Nextcloud sync stuck']);
        $this->ticketFor($this->bob, ['subject' => 'Invoice question']);

        $this->actingAs($this->admin)->get('/admin/support/tickets?assigned=all&q=nextcloud')
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1)->where('tickets.data.0.number', $wanted->number));

        $this->actingAs($this->admin)->get('/admin/support/tickets?assigned=all&q=KCP-'.$wanted->number)
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1)->where('tickets.data.0.number', $wanted->number));
    }

    public function test_a_search_with_wildcards_is_treated_literally(): void
    {
        $this->ticketFor($this->alice, ['subject' => 'Plain subject']);

        $this->actingAs($this->admin)->get('/admin/support/tickets?assigned=all&q=%25')
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 0));
    }

    public function test_ticket_count_is_unchanged_by_failed_creates(): void
    {
        $before = Ticket::count();

        $this->actingAs($this->alice)->post('/support/tickets', ['subject' => 'x', 'body' => '<p>x</p>', 'type' => 'not-a-type'])
            ->assertSessionHasErrors('type');

        $this->assertSame($before, Ticket::count());
    }
}
