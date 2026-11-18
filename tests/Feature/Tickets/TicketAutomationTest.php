<?php

namespace Tests\Feature\Tickets;

use App\Application;
use App\Enums\TicketStatus;
use App\Notifications\Tickets\TicketActivity;
use App\Services\Tickets\TicketAutomation;
use App\Services\Tickets\TicketAutomationRunner;
use App\Ticket;
use App\TicketCategory;
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
        $target = $this->ticketFor($this->alice);
        $source = $this->ticketIn(['status' => TicketStatus::PENDING_CUSTOMER, 'merged_into_id' => $target->id, 'last_activity_at' => now()->subDays(10)]);
        // Switched on after the tickets exist, so creating them does not assign them
        $this->automation()->update(['auto_assign' => 'round_robin', 'reminder_days' => 1]);

        $this->runner()->run();

        $this->assertNull($source->fresh()->reminder_sent_at);
        $this->assertNull($source->fresh()->support_assignee_id);
    }

    // ---- rules per app

    public function test_app_rules_are_stored_as_a_map_without_duplicates_or_empty_rules(): void
    {
        $this->automation()->update(['auto_assign' => 'round_robin', 'app_rules' => [
            ['application_id' => 10, 'pool_ids' => [1, 2, 2]],
            ['application_id' => 10, 'pool_ids' => [3]],
            ['application_id' => 20, 'pool_ids' => []],
            ['application_id' => 0, 'pool_ids' => [1]],
        ]]);

        $this->assertSame([10 => [1, 2]], $this->automation()->settings()['app_rules']);

        // An update that does not mention them leaves them alone; an empty list clears them
        $this->automation()->update(['reminder_days' => 5]);
        $this->assertSame([10 => [1, 2]], $this->automation()->settings()['app_rules']);
        $this->automation()->update(['app_rules' => []]);
        $this->assertSame([], $this->automation()->settings()['app_rules']);
    }

    public function test_tickets_about_an_app_take_turns_among_the_people_chosen_for_it(): void
    {
        $sam = $this->staffMember('sam');
        $tess = $this->staffMember('tess');
        $nextcloud = Application::factory()->create();
        $wordpress = Application::factory()->create();
        $nextcloudApp = $this->appInstance($this->orgA, $nextcloud, 'Files');
        $wordpressApp = $this->appInstance($this->orgA, $wordpress, 'Blog');

        $this->automation()->update([
            'auto_assign' => 'round_robin',
            'pool_ids' => [$this->admin->id],
            'app_rules' => [
                ['application_id' => $nextcloud->id, 'pool_ids' => [$sam->id, $tess->id]],
                ['application_id' => $wordpress->id, 'pool_ids' => [$tess->id]],
            ],
        ]);

        $assignee = fn (array $data = []) => $this->ticketFor($this->alice, $data)->fresh()->support_assignee_id;

        $first = $assignee(['app_instance_id' => $nextcloudApp->id]);
        $second = $assignee(['app_instance_id' => $nextcloudApp->id]);
        $third = $assignee(['app_instance_id' => $nextcloudApp->id]);

        // Turns inside the app's own people, nobody else
        $this->assertEqualsCanonicalizing([$sam->id, $tess->id], [$first, $second]);
        $this->assertSame($first, $third);

        // One person for the other app; no app (or an app without a rule) uses the general pool
        $this->assertSame($tess->id, $assignee(['app_instance_id' => $wordpressApp->id]));
        $this->assertSame($this->admin->id, $assignee());
        $other = $this->appInstance($this->orgA, Application::factory()->create(), 'Other');
        $this->assertSame($this->admin->id, $assignee(['app_instance_id' => $other->id]));
    }

    public function test_a_category_default_still_wins_over_the_app_rule(): void
    {
        $sam = $this->staffMember('sam');
        $tess = $this->staffMember('tess');
        $application = Application::factory()->create();
        $app = $this->appInstance($this->orgA, $application);
        $category = new TicketCategory(['name' => 'Billing', 'slug' => 'billing']);
        $category->active = true;
        $category->default_support_assignee_id = $tess->id;
        $category->save();
        $this->automation()->update(['auto_assign' => 'round_robin', 'app_rules' => [['application_id' => $application->id, 'pool_ids' => [$sam->id]]]]);

        $ticket = $this->ticketFor($this->alice, ['app_instance_id' => $app->id, 'category_id' => $category->id]);

        $this->assertSame($tess->id, $ticket->fresh()->support_assignee_id);
    }

    public function test_when_nobody_on_the_apps_rule_is_available_the_general_pool_is_used(): void
    {
        $sam = $this->staffMember('sam');
        $application = Application::factory()->create();
        $app = $this->appInstance($this->orgA, $application);
        $sam->forceFill(['is_allowed' => false])->save();
        $this->automation()->update(['auto_assign' => 'round_robin', 'pool_ids' => [$this->admin->id], 'app_rules' => [['application_id' => $application->id, 'pool_ids' => [$sam->id]]]]);

        $this->assertSame($this->admin->id, $this->ticketFor($this->alice, ['app_instance_id' => $app->id])->fresh()->support_assignee_id);
    }

    public function test_the_runner_uses_the_app_rules_for_tickets_nobody_was_assigned(): void
    {
        $sam = $this->staffMember('sam');
        $application = Application::factory()->create();
        $app = $this->appInstance($this->orgA, $application);
        $ticket = $this->ticketFor($this->alice, ['app_instance_id' => $app->id]);

        $this->automation()->update(['auto_assign' => 'least_loaded', 'pool_ids' => [$this->admin->id], 'app_rules' => [['application_id' => $application->id, 'pool_ids' => [$sam->id]]]]);
        $this->runner()->run();

        $this->assertSame($sam->id, $ticket->fresh()->support_assignee_id);
    }

    public function test_off_means_off_whatever_the_rules(): void
    {
        $sam = $this->staffMember('sam');
        $application = Application::factory()->create();
        $app = $this->appInstance($this->orgA, $application);
        $this->automation()->update(['auto_assign' => 'off', 'app_rules' => [['application_id' => $application->id, 'pool_ids' => [$sam->id]]]]);

        $this->assertNull($this->ticketFor($this->alice, ['app_instance_id' => $app->id])->fresh()->support_assignee_id);
    }
}
