<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Notifications\Tickets\TicketActivity;
use App\Services\Tickets\TicketSubscriptions;
use App\TicketSubscription;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

class WatchersTest extends TicketsTestCase
{
    private function sentTo($user, ?string $event = null): bool
    {
        return Notification::sent($user, TicketActivity::class, fn (TicketActivity $n) => $event === null || $n->event === $event)->isNotEmpty();
    }

    public function test_a_member_can_copy_a_colleague(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number.'/watchers', ['user_ids' => [$this->bob->id]])->assertRedirect();

        $this->assertSame([$this->bob->id], app(TicketSubscriptions::class)->watcherIds($ticket));
        $this->assertSame('customer', TicketSubscription::where('user_id', $this->bob->id)->value('side'));
    }

    public function test_the_list_can_be_emptied_again(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->syncWatchers($ticket, [$this->bob->id], $this->alice, false);

        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number.'/watchers', ['user_ids' => []])->assertRedirect();

        $this->assertSame([], app(TicketSubscriptions::class)->watcherIds($ticket));
        $this->assertSame(0, TicketSubscription::count());
    }

    public function test_customers_cannot_copy_people_outside_their_organization_or_staff(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number.'/watchers', ['user_ids' => [$this->carol->id]])->assertSessionHasErrors('user_ids');
        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number.'/watchers', ['user_ids' => [$this->admin->id]])->assertSessionHasErrors('user_ids');
        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number.'/watchers', ['user_ids' => [999999]])->assertSessionHasErrors('user_ids');

        $this->assertSame(0, TicketSubscription::count());
    }

    public function test_deactivated_people_cannot_be_copied(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->bob->update(['is_allowed' => false]);

        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number.'/watchers', ['user_ids' => [$this->bob->id]])->assertSessionHasErrors('user_ids');
    }

    public function test_another_organization_cannot_change_the_watchers(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->carol)->put('/support/tickets/'.$ticket->number.'/watchers', ['user_ids' => [$this->carol->id]])->assertNotFound();
    }

    public function test_staff_can_copy_members_and_other_staff(): void
    {
        $colleague = $this->staffMember('colleague');
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->admin)->put('/admin/support/tickets/'.$ticket->number.'/watchers', ['user_ids' => [$this->bob->id, $colleague->id]])->assertRedirect();

        $this->assertSame('customer', TicketSubscription::where('user_id', $this->bob->id)->value('side'));
        $this->assertSame('support', TicketSubscription::where('user_id', $colleague->id)->value('side'));
    }

    public function test_staff_cannot_copy_people_from_other_organizations(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->admin)->put('/admin/support/tickets/'.$ticket->number.'/watchers', ['user_ids' => [$this->carol->id]])->assertSessionHasErrors('user_ids');
    }

    public function test_only_staff_can_use_the_admin_watcher_route(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->put('/admin/support/tickets/'.$ticket->number.'/watchers', ['user_ids' => [$this->bob->id]])->assertForbidden();
    }

    public function test_a_customer_changing_the_list_never_touches_staff_watchers(): void
    {
        $colleague = $this->staffMember('colleague');
        $ticket = $this->ticketFor($this->alice);
        $this->service()->syncWatchers($ticket, [$this->bob->id, $colleague->id], $this->admin, true);

        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number.'/watchers', ['user_ids' => []])->assertRedirect();

        $this->assertSame([$colleague->id], app(TicketSubscriptions::class)->watcherIds($ticket));
    }

    public function test_watchers_hear_about_public_replies(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->syncWatchers($ticket, [$this->bob->id], $this->alice, false);
        Notification::fake();

        $this->reply($ticket, $this->admin, '<p>Update</p>', staff: true);

        $this->assertTrue($this->sentTo($this->bob, 'reply'));
        $this->assertFalse($this->sentTo($this->carol));
    }

    public function test_a_customer_watcher_never_hears_about_internal_notes(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->syncWatchers($ticket, [$this->bob->id], $this->alice, false);
        Notification::fake();

        $this->reply($ticket, $this->admin, '<p>Private</p>', staff: true, internal: true);

        $this->assertFalse($this->sentTo($this->bob));
    }

    public function test_staff_watchers_hear_about_internal_notes_and_customer_replies(): void
    {
        $colleague = $this->staffMember('colleague');
        $ticket = $this->ticketFor($this->alice);
        $this->service()->assign($ticket, 'support', $this->admin, $this->admin);
        $this->service()->syncWatchers($ticket, [$colleague->id], $this->admin, true);
        Notification::fake();

        $this->reply($ticket, $this->admin, '<p>Note</p>', staff: true, internal: true);
        $this->assertTrue($this->sentTo($colleague, 'note'));

        Notification::fake();
        $this->reply($ticket, $this->alice, '<p>Customer says hi</p>');
        $this->assertTrue($this->sentTo($colleague, 'reply'));
    }

    public function test_the_watcher_change_is_staff_only_in_the_timeline(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->syncWatchers($ticket, [$this->bob->id], $this->alice, false);

        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number)
            ->assertInertia(fn (Assert $page) => $page->where('timeline', fn ($timeline) => ! $timeline->pluck('type')->contains('watchers_changed')));
        $this->actingAs($this->admin)->get('/admin/support/tickets/'.$ticket->number)
            ->assertInertia(fn (Assert $page) => $page->where('timeline', fn ($timeline) => $timeline->pluck('type')->contains('watchers_changed')));
    }

    public function test_the_page_lists_the_current_watchers_and_whether_i_muted_it(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->syncWatchers($ticket, [$this->bob->id], $this->alice, false);
        $this->service()->setMuted($ticket, $this->alice, true, false);

        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number)
            ->assertInertia(fn (Assert $page) => $page->where('watcher_ids', [$this->bob->id])->where('muted', true));
    }

    public function test_muting_silences_a_ticket_but_not_assignments(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->assign($ticket, 'customer', $this->bob, $this->bob);
        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number.'/subscription', ['muted' => true])->assertRedirect();
        Notification::fake();

        // Alice is still the requester, so she would normally hear about both of these
        $this->reply($ticket, $this->admin, '<p>Update</p>', staff: true);
        $this->service()->changeStatus($ticket, $this->admin, TicketStatus::RESOLVED, true);
        $this->assertFalse($this->sentTo($this->alice));

        // Being handed the ticket is still worth knowing
        $this->service()->assign($ticket, 'customer', $this->alice, $this->bob);
        $this->assertTrue($this->sentTo($this->alice, 'assigned'));
    }

    public function test_unmuting_restores_notifications_and_leaves_no_empty_row(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->setMuted($ticket, $this->alice, true, false);
        $this->assertSame(1, TicketSubscription::count());

        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number.'/subscription', ['muted' => false])->assertRedirect();

        $this->assertSame(0, TicketSubscription::count());
        Notification::fake();
        $this->reply($ticket, $this->admin, '<p>Update</p>', staff: true);
        $this->assertTrue($this->sentTo($this->alice, 'reply'));
    }

    public function test_muting_a_ticket_of_another_organization_is_a_404(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->carol)->put('/support/tickets/'.$ticket->number.'/subscription', ['muted' => true])->assertNotFound();
        $this->assertSame(0, TicketSubscription::count());
    }

    public function test_staff_can_mute_too(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->service()->assign($ticket, 'support', $this->admin, $this->admin);

        $this->actingAs($this->admin)->put('/admin/support/tickets/'.$ticket->number.'/subscription', ['muted' => true])->assertRedirect();
        Notification::fake();
        $this->reply($ticket, $this->alice, '<p>Hello?</p>');

        $this->assertFalse($this->sentTo($this->admin));
    }
}
