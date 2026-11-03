<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Notifications\Tickets\TicketActivity;
use App\Services\Tickets\TicketAutomation;
use App\Services\Tickets\TicketAutomationRunner;
use App\Ticket;
use App\TicketEvent;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

class TicketAutomationTest extends TicketsTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function automation(): TicketAutomation
    {
        return app(TicketAutomation::class);
    }

    private function runner(): TicketAutomationRunner
    {
        return app(TicketAutomationRunner::class);
    }

    /**
     * @param  array<string, mixed>  $state  Columns to force, such as status or last_activity_at
     */
    private function ticketIn(array $state, ?User $requester = null): Ticket
    {
        $ticket = $this->ticketFor($requester ?? $this->alice);
        $ticket->forceFill($state)->save();

        return $ticket->fresh();
    }

    public function test_the_defaults_do_nothing(): void
    {
        $settings = $this->automation()->settings();

        $this->assertSame('off', $settings['auto_assign']);
        $this->assertNull($this->automation()->pickAssignee());
    }

    public function test_values_are_clamped_and_unknown_keys_ignored(): void
    {
        $this->automation()->update(['auto_assign' => 'round_robin', 'reminder_days' => '5', 'retention_days' => 99999, 'auto_close_days' => -3, 'bogus' => 1]);

        $settings = $this->automation()->settings();

        $this->assertSame(5, $settings['reminder_days']);
        $this->assertSame(3650, $settings['retention_days']);
        $this->assertSame(0, $settings['auto_close_days']);
        $this->assertArrayNotHasKey('bogus', $settings);
    }

    public function test_round_robin_takes_turns_between_the_support_team(): void
    {
        $sam = $this->staffMember('sam');
        $tess = $this->staffMember('tess');
        $this->automation()->update(['auto_assign' => 'round_robin']);

        $team = [$this->admin->id, $sam->id, $tess->id];
        sort($team);

        $picks = [];
        for ($i = 0; $i < 6; $i++) {
            $picks[] = $this->automation()->pickAssignee()->id;
        }

        $this->assertSame(array_merge($team, $team), $picks);
    }

    public function test_least_loaded_picks_whoever_has_the_fewest_open_tickets(): void
    {
        $sam = $this->staffMember('sam');
        $this->automation()->update(['auto_assign' => 'least_loaded', 'pool_ids' => [$this->admin->id, $sam->id]]);

        $this->ticketIn(['support_assignee_id' => $this->admin->id]);
        $this->ticketIn(['support_assignee_id' => $this->admin->id]);
        $this->ticketIn(['support_assignee_id' => $sam->id]);

        $this->assertSame($sam->id, $this->automation()->pickAssignee()->id);

        // A closed ticket is not load
        $this->ticketIn(['support_assignee_id' => $sam->id, 'status' => TicketStatus::CLOSED]);
        $this->assertSame($sam->id, $this->automation()->pickAssignee()->id);
    }

    public function test_the_pool_limits_who_can_be_picked(): void
    {
        $sam = $this->staffMember('sam');
        $this->automation()->update(['auto_assign' => 'round_robin', 'pool_ids' => [$sam->id]]);

        $this->assertSame($sam->id, $this->automation()->pickAssignee()->id);
        $this->assertSame($sam->id, $this->automation()->pickAssignee()->id);
    }

    public function test_new_tickets_are_assigned_as_they_come_in(): void
    {
        $sam = $this->staffMember('sam');
        $this->automation()->update(['auto_assign' => 'round_robin', 'pool_ids' => [$sam->id]]);

        $ticket = $this->ticketFor($this->alice);

        $this->assertSame($sam->id, $ticket->fresh()->support_assignee_id);
        // Nobody did it, so it says "the system" rather than a person
        $event = TicketEvent::where('ticket_id', $ticket->id)->where('type', 'assigned')->firstOrFail();
        $this->assertNull($event->actor_id);
    }

    public function test_the_runner_gives_unassigned_open_tickets_someone(): void
    {
        $first = $this->ticketFor($this->alice);
        $second = $this->ticketFor($this->bob);
        $sam = $this->staffMember('sam');

        $this->automation()->update(['auto_assign' => 'round_robin', 'pool_ids' => [$sam->id]]);
        $result = $this->runner()->run();

        $this->assertSame(2, $result['assigned']);
        $this->assertSame($sam->id, $first->fresh()->support_assignee_id);
        $this->assertSame($sam->id, $second->fresh()->support_assignee_id);
    }

    public function test_reminders_resolution_closing_and_retention(): void
    {
        $this->automation()->update(['auto_assign' => 'off', 'reminder_days' => 3, 'auto_resolve_days' => 7, 'auto_close_days' => 7, 'retention_days' => 30]);
        Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00', 'UTC'));
        $at = fn (string $time) => Carbon::parse($time, 'UTC');

        $waitingOld = $this->ticketIn(['status' => TicketStatus::PENDING_CUSTOMER, 'last_activity_at' => $at('2026-11-26 09:00')]);
        $waitingVeryOld = $this->ticketIn(['status' => TicketStatus::PENDING_CUSTOMER, 'last_activity_at' => $at('2026-11-20 09:00'), 'reminder_sent_at' => $at('2026-11-23 09:00')]);
        $waitingFresh = $this->ticketIn(['status' => TicketStatus::PENDING_CUSTOMER, 'last_activity_at' => $at('2026-11-30 09:00')]);
        $resolvedOld = $this->ticketIn(['status' => TicketStatus::RESOLVED, 'resolved_at' => $at('2026-11-20 09:00')]);
        $resolvedFresh = $this->ticketIn(['status' => TicketStatus::RESOLVED, 'resolved_at' => $at('2026-11-29 09:00')]);
        $closedAncient = $this->ticketIn(['status' => TicketStatus::CLOSED, 'closed_at' => $at('2026-09-01 09:00'), 'resolved_at' => $at('2026-09-01 09:00')]);
        $closedRecent = $this->ticketIn(['status' => TicketStatus::CLOSED, 'closed_at' => $at('2026-11-25 09:00'), 'resolved_at' => $at('2026-11-25 09:00')]);
        $openOld = $this->ticketIn(['status' => TicketStatus::OPEN, 'last_activity_at' => $at('2026-10-01 09:00')]);

        $result = $this->runner()->run();

        $this->assertSame(['assigned' => 0, 'reminded' => 1, 'resolved' => 1, 'closed' => 1, 'purged' => 1], array_intersect_key($result, array_flip(['assigned', 'reminded', 'resolved', 'closed', 'purged'])));

        // Only the one waiting 3+ days that was not reminded yet
        $this->assertNotNull($waitingOld->fresh()->reminder_sent_at);
        $this->assertNull($waitingFresh->fresh()->reminder_sent_at);
        $this->assertSame(1, TicketEvent::where('type', 'reminder_sent')->count());

        // The long-silent one is resolved, by "the system"
        $this->assertSame(TicketStatus::RESOLVED, $waitingVeryOld->fresh()->status);
        $event = TicketEvent::where('ticket_id', $waitingVeryOld->id)->where('type', 'status_changed')->latest('id')->firstOrFail();
        $this->assertNull($event->actor_id);
        $this->assertTrue($event->context['auto']);

        $this->assertSame(TicketStatus::CLOSED, $resolvedOld->fresh()->status);
        $this->assertSame(TicketStatus::RESOLVED, $resolvedFresh->fresh()->status);

        // Retention removes the old closed ticket only; open tickets are never touched
        $this->assertNull(Ticket::find($closedAncient->id));
        $this->assertNotNull(Ticket::find($closedRecent->id));
        $this->assertSame(TicketStatus::OPEN, $openOld->fresh()->status);

        // Running again does nothing more
        $second = $this->runner()->run();
        $this->assertSame(0, $second['reminded'] + $second['resolved'] + $second['purged']);
    }

    public function test_the_customer_is_told_about_a_reminder_and_gets_another_after_answering(): void
    {
        $this->automation()->update(['reminder_days' => 3]);
        Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00', 'UTC'));
        $ticket = $this->ticketIn(['status' => TicketStatus::PENDING_CUSTOMER, 'last_activity_at' => Carbon::parse('2026-11-26 09:00', 'UTC')]);
        Notification::fake();

        $this->runner()->run();

        Notification::assertSentTo($this->alice, TicketActivity::class, fn (TicketActivity $notification) => $notification->event === 'reminder');

        // The flag is reset when the status changes, so a later silence is reminded again
        $this->service()->changeStatus($ticket->fresh(), null, TicketStatus::PENDING_SUPPORT, true);
        $this->assertNull($ticket->fresh()->reminder_sent_at);
    }

    public function test_merged_tickets_are_left_alone(): void
    {
        $this->automation()->update(['auto_assign' => 'round_robin', 'reminder_days' => 1]);
        $target = $this->ticketFor($this->alice);
        $source = $this->ticketIn(['status' => TicketStatus::PENDING_CUSTOMER, 'merged_into_id' => $target->id, 'last_activity_at' => now()->subDays(10)]);

        $this->runner()->run();

        $this->assertNull($source->fresh()->reminder_sent_at);
        $this->assertNull($source->fresh()->support_assignee_id);
    }
}
