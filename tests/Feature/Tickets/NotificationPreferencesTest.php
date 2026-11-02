<?php

namespace Tests\Feature\Tickets;

use App\Channels\PanelChannel;
use App\Notifications\Tickets\TicketActivity;
use App\Services\Tickets\TicketPreferences;
use App\TicketMessage;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mime\Email;

class NotificationPreferencesTest extends TicketsTestCase
{
    private function allows($user, string $event, string $channel): bool
    {
        return app(TicketPreferences::class)->allows($user->fresh(), $event, $channel);
    }

    public function test_everything_is_on_by_default(): void
    {
        $matrix = app(TicketPreferences::class)->matrix($this->alice);

        foreach (TicketPreferences::EVENTS as $event) {
            $this->assertTrue($matrix[$event]['mail']);
            $this->assertTrue($matrix[$event]['panel']);
        }
    }

    public function test_a_person_can_switch_off_email_for_one_event(): void
    {
        $this->actingAs($this->alice)->put('/support/tickets/preferences', [
            'preferences' => ['reply' => ['mail' => false, 'panel' => true]],
        ])->assertRedirect();

        $this->assertFalse($this->allows($this->alice, 'reply', 'mail'));
        $this->assertTrue($this->allows($this->alice, 'reply', 'panel'));
        // Everything else untouched
        $this->assertTrue($this->allows($this->alice, 'status', 'mail'));
        // ...and nobody else is affected
        $this->assertTrue($this->allows($this->bob, 'reply', 'mail'));
    }

    public function test_the_settings_apply_per_channel_when_sending(): void
    {
        $ticket = $this->ticketFor($this->alice);
        app(TicketPreferences::class)->update($this->alice, ['reply' => ['mail' => false]]);
        $notification = new TicketActivity($ticket, 'reply');

        $this->assertFalse($notification->shouldSend($this->alice->fresh(), 'mail'));
        $this->assertTrue($notification->shouldSend($this->alice->fresh(), 'panel'));
        $this->assertTrue($notification->shouldSend($this->bob, 'mail'));
    }

    public function test_switching_both_channels_off_means_no_notification_at_all(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->actingAs($this->alice)->put('/support/tickets/preferences', [
            'preferences' => ['reply' => ['mail' => false, 'panel' => false]],
        ]);
        Notification::fake();

        $this->reply($ticket, $this->admin, '<p>Update</p>', staff: true);

        Notification::assertNotSentTo($this->alice->fresh(), TicketActivity::class);
    }

    public function test_unknown_events_and_bad_values_are_refused_or_ignored(): void
    {
        $this->actingAs($this->alice)->put('/support/tickets/preferences', ['preferences' => ['reply' => ['mail' => 'maybe']]])->assertSessionHasErrors();
        $this->actingAs($this->alice)->put('/support/tickets/preferences', [])->assertSessionHasErrors('preferences');

        $this->actingAs($this->alice)->put('/support/tickets/preferences', ['preferences' => ['made_up' => ['mail' => false]]])->assertRedirect();
        $this->assertArrayNotHasKey('made_up', $this->alice->fresh()->ticket_preferences);
    }

    public function test_guests_cannot_save_preferences(): void
    {
        $this->putJson('/support/tickets/preferences', ['preferences' => ['reply' => ['mail' => false]]])->assertUnauthorized();
    }

    public function test_customers_are_not_offered_the_internal_note_switch_but_staff_are(): void
    {
        $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->get('/support/tickets')
            ->assertInertia(fn (Assert $page) => $page->where('preferences.events', fn ($events) => ! $events->contains('note') && $events->contains('reply')));
        $this->actingAs($this->admin)->get('/admin/support/tickets')
            ->assertInertia(fn (Assert $page) => $page->where('preferences.events', fn ($events) => $events->contains('note')));
    }

    public function test_saved_settings_come_back_on_the_page(): void
    {
        app(TicketPreferences::class)->update($this->alice, ['status' => ['panel' => false]]);

        $this->actingAs($this->alice)->get('/support/tickets')
            ->assertInertia(fn (Assert $page) => $page->where('preferences.matrix.status.panel', false)->where('preferences.matrix.status.mail', true));
    }

    public function test_reply_emails_are_delayed_but_panel_notifications_are_not(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $message = $this->reply($ticket, $this->admin, '<p>Update</p>', staff: true);
        $notification = new TicketActivity($ticket, 'reply', false, [], null, $message->id);

        $this->assertNotNull($notification->withDelay($this->alice, 'mail'));
        $this->assertNull($notification->withDelay($this->alice, PanelChannel::class));
        // Things that are not part of a conversation are sent straight away
        $this->assertNull((new TicketActivity($ticket, 'status'))->withDelay($this->alice, 'mail'));

        config(['tickets.mail_delay_seconds' => 0]);
        $this->assertNull($notification->withDelay($this->alice, 'mail'));
    }

    public function test_a_reply_email_is_skipped_once_the_person_has_read_the_message(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $message = $this->reply($ticket, $this->admin, '<p>Update</p>', staff: true);
        $notification = new TicketActivity($ticket, 'reply', false, [], null, $message->id);

        $this->assertTrue($notification->shouldSend($this->alice, 'mail'));

        // Alice opens the ticket during the delay
        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number);

        $this->assertFalse($notification->shouldSend($this->alice->fresh(), 'mail'));
        // The in-panel notification is unaffected
        $this->assertTrue($notification->shouldSend($this->alice->fresh(), 'panel'));
    }

    public function test_a_burst_of_replies_becomes_one_email(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $first = $this->reply($ticket, $this->admin, '<p>One</p>', staff: true);
        $second = $this->reply($ticket, $this->admin, '<p>Two</p>', staff: true);
        $third = $this->reply($ticket, $this->admin, '<p>Three</p>', staff: true);

        $send = fn (TicketMessage $message) => (new TicketActivity($ticket, 'reply', false, [], null, $message->id))->shouldSend($this->alice, 'mail');

        $this->assertFalse($send($first));
        $this->assertFalse($send($second));
        $this->assertTrue($send($third));
    }

    public function test_someone_elses_later_message_does_not_swallow_the_email(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $staff = $this->reply($ticket, $this->admin, '<p>From support</p>', staff: true);
        $this->reply($ticket, $this->bob, '<p>From a colleague</p>');

        $this->assertTrue((new TicketActivity($ticket, 'reply', false, [], null, $staff->id))->shouldSend($this->alice, 'mail'));
    }

    public function test_internal_notes_and_public_replies_do_not_coalesce_with_each_other(): void
    {
        $colleague = $this->staffMember('colleague');
        $ticket = $this->ticketFor($this->alice);
        $this->service()->assign($ticket, 'support', $this->admin, $this->admin);
        $note = $this->reply($ticket, $colleague, '<p>Note</p>', staff: true, internal: true);
        $reply = $this->reply($ticket, $colleague, '<p>Public</p>', staff: true);

        // The later public reply is not a later note, so the note's email still goes
        $this->assertTrue((new TicketActivity($ticket, 'note', true, [], null, $note->id))->shouldSend($this->admin, 'mail'));
        $this->assertTrue((new TicketActivity($ticket, 'reply', false, [], null, $reply->id))->shouldSend($this->alice, 'mail'));
    }

    public function test_the_first_email_starts_the_thread_and_later_ones_join_it(): void
    {
        config(['app.url' => 'https://panel.example.test']);
        $ticket = $this->ticketFor($this->alice);

        $headers = function (string $event) use ($ticket) {
            $mail = (new TicketActivity($ticket, $event))->toMail($this->alice);
            $email = (new Email)->from('a@example.test')->to('b@example.test')->text('x');
            foreach ($mail->callbacks as $callback) {
                $callback($email);
            }

            return $email->getHeaders();
        };

        $root = '<ticket-'.$ticket->id.'@panel.example.test>';

        $created = $headers('created');
        $this->assertSame($root, '<'.$created->get('Message-ID')->getId().'>');
        $this->assertNull($created->get('In-Reply-To'));

        $reply = $headers('reply');
        $this->assertSame($root, '<'.$reply->get('In-Reply-To')->getId().'>');
        $this->assertSame($root, '<'.$reply->get('References')->getId().'>');
    }

    public function test_the_subject_always_carries_the_ticket_reference(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $mail = (new TicketActivity($ticket, 'reply'))->toMail($this->alice);

        $this->assertSame('['.$ticket->reference.'] '.$ticket->subject, $mail->subject);
    }
}
