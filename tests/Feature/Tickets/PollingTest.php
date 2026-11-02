<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Services\Tickets\TicketVersions;
use App\Ticket;
use Inertia\Testing\AssertableInertia as Assert;

class PollingTest extends TicketsTestCase
{
    private function version(Ticket $ticket): string
    {
        return app(TicketVersions::class)->ticket($ticket);
    }

    public function test_an_unchanged_ticket_answers_304_without_a_body(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number.'/poll?v='.$this->version($ticket))
            ->assertStatus(304);
    }

    public function test_a_changed_ticket_returns_the_new_version_and_how_many_messages_are_new(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $seen = $ticket->messages()->max('id');
        $old = $this->version($ticket);

        $this->reply($ticket, $this->admin, '<p>One</p>', staff: true);
        $this->reply($ticket, $this->admin, '<p>Two</p>', staff: true);

        $this->assertNotSame($old, $this->version($ticket));

        $this->actingAs($this->alice)->getJson('/support/tickets/'.$ticket->number.'/poll?v='.$old.'&after='.$seen)
            ->assertOk()
            ->assertJsonPath('version', $this->version($ticket))
            ->assertJsonPath('new_messages', 2)
            ->assertJsonPath('status', 'pending_customer');
    }

    public function test_your_own_messages_are_not_new_to_you(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $seen = $ticket->messages()->max('id');
        $old = $this->version($ticket);

        $this->reply($ticket, $this->alice, '<p>Following up</p>');

        $this->actingAs($this->alice)->getJson('/support/tickets/'.$ticket->number.'/poll?v='.$old.'&after='.$seen)
            ->assertOk()->assertJsonPath('new_messages', 0);
    }

    public function test_internal_notes_are_never_counted_for_customers(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $seen = $ticket->messages()->max('id');
        $old = $this->version($ticket);

        $colleague = $this->staffMember('colleague');
        $this->reply($ticket, $colleague, '<p>Private</p>', staff: true, internal: true);

        $this->actingAs($this->alice)->getJson('/support/tickets/'.$ticket->number.'/poll?v='.$old.'&after='.$seen)
            ->assertOk()->assertJsonPath('new_messages', 0);
        // Other staff do see it
        $this->actingAs($this->admin)->getJson('/admin/support/tickets/'.$ticket->number.'/poll?v='.$old.'&after='.$seen)
            ->assertOk()->assertJsonPath('new_messages', 1);
    }

    public function test_every_kind_of_change_moves_the_version(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $changes = [
            'reply' => fn () => $this->reply($ticket, $this->bob),
            'edit' => fn () => $this->service()->editMessage($ticket->messages()->first(), $this->alice, '<p>Edited</p>', false),
            'status' => fn () => $this->service()->changeStatus($ticket, $this->admin, TicketStatus::ON_HOLD, true),
            'priority' => fn () => $this->service()->changePriority($ticket, $this->admin, TicketPriority::HIGH),
            'assign' => fn () => $this->service()->assign($ticket, 'support', $this->admin, $this->admin),
            'tags' => fn () => $this->service()->syncTags($ticket, ['vip'], $this->admin),
        ];

        foreach ($changes as $name => $change) {
            $before = $this->version($ticket);
            $change();
            $this->assertNotSame($before, $this->version($ticket), "{$name} did not change the ticket version");
        }
    }

    public function test_another_ticket_is_unaffected_by_changes(): void
    {
        $mine = $this->ticketFor($this->alice);
        $other = $this->ticketFor($this->bob);
        $before = $this->version($mine);

        $this->reply($other, $this->admin, '<p>Hi</p>', staff: true);

        $this->assertSame($before, $this->version($mine));
    }

    public function test_another_organization_cannot_poll_a_ticket(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->carol)->getJson('/support/tickets/'.$ticket->number.'/poll')->assertNotFound();
    }

    public function test_the_admin_poll_is_for_staff_only(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->getJson('/admin/support/tickets/'.$ticket->number.'/poll')->assertForbidden();
        $this->actingAs($this->admin)->getJson('/admin/support/tickets/'.$ticket->number.'/poll')->assertOk();
    }

    public function test_the_page_is_given_the_version_to_poll_with(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number)
            ->assertInertia(fn (Assert $page) => $page
                ->where('version', $this->version($ticket))
                ->where('last_message_id', $ticket->messages()->max('id')));
    }

    public function test_the_summary_counts_unread_tickets_the_person_is_involved_in(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->ticketFor($this->bob);
        $this->reply($ticket, $this->admin, '<p>News</p>', staff: true);

        $this->actingAs($this->alice)->getJson('/support/tickets/summary')->assertOk()->assertJsonPath('unread', 1);
        // Bob has no part in Alice's ticket
        $this->actingAs($this->bob)->getJson('/support/tickets/summary')->assertOk()->assertJsonPath('unread', 0);

        // Reading it clears the badge
        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number);
        $this->actingAs($this->alice)->getJson('/support/tickets/summary')->assertJsonPath('unread', 0);
    }

    public function test_the_summary_says_whether_the_list_is_stale(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $version = app(TicketVersions::class)->organization($this->orgA->id);

        $this->actingAs($this->alice)->getJson('/support/tickets/summary?v='.$version)->assertJsonPath('changed', false);

        $this->reply($ticket, $this->bob);

        $this->actingAs($this->alice)->getJson('/support/tickets/summary?v='.$version)
            ->assertJsonPath('changed', true)
            ->assertJsonPath('version', app(TicketVersions::class)->organization($this->orgA->id));
        // Asking without a version never reports a change
        $this->actingAs($this->alice)->getJson('/support/tickets/summary')->assertJsonPath('changed', false);
    }

    public function test_the_organization_version_does_not_move_for_other_organizations(): void
    {
        $this->ticketFor($this->alice);
        $version = app(TicketVersions::class)->organization($this->orgA->id);

        $this->ticketFor($this->carol);

        $this->assertSame($version, app(TicketVersions::class)->organization($this->orgA->id));
    }

    public function test_only_staff_can_ask_for_the_global_version(): void
    {
        $this->ticketFor($this->alice);
        $all = app(TicketVersions::class)->all();
        $org = app(TicketVersions::class)->organization($this->orgA->id);

        $this->actingAs($this->admin)->getJson('/support/tickets/summary?scope=all')->assertJsonPath('version', $all);
        // A customer asking for scope=all just gets their organization's
        $this->actingAs($this->alice)->getJson('/support/tickets/summary?scope=all')->assertJsonPath('version', $org);
    }

    public function test_summary_needs_a_signed_in_user_and_the_feature(): void
    {
        $this->getJson('/support/tickets/summary')->assertUnauthorized();

        config(['toggle.flags.tickets' => false]);
        $this->actingAs($this->alice)->getJson('/support/tickets/summary')->assertNotFound();
    }
}
