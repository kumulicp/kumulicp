<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Notifications\Tickets\TicketActivity;
use App\Ticket;
use App\TicketDelivery;
use App\TicketEvent;
use App\TicketMessage;
use App\TicketMessageRevision;
use App\TicketSubscription;
use App\TicketTag;
use App\TicketTimeEntry;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

class TicketMergeRatingTest extends TicketsTestCase
{
    // ---- merge

    public function test_staff_merge_a_duplicate_into_another_ticket(): void
    {
        $target = $this->ticketFor($this->alice, ['subject' => 'Cannot log in']);
        $source = $this->ticketFor($this->bob, ['subject' => 'Login broken too']);
        $this->reply($source, $this->admin, '<p>Looking into it</p>', staff: true);
        $entry = new TicketTimeEntry;
        $entry->ticket_id = $source->id;
        $entry->user_id = $this->admin->id;
        $entry->minutes = 15;
        $entry->worked_at = now()->toDateString();
        $entry->save();
        $source->tags()->attach(TicketTag::create(['name' => 'vip'])->id);
        Notification::fake();

        $this->actingAs($this->admin)
            ->post('/admin/support/tickets/'.$source->number.'/merge', ['target_number' => $target->number])
            ->assertRedirect('/admin/support/tickets/'.$target->number);

        $source->refresh();
        $target->refresh();

        // The conversation moved, in order, and time and tags came along
        $this->assertSame(3, TicketMessage::where('ticket_id', $target->id)->count());
        $this->assertSame(1, TicketTimeEntry::where('ticket_id', $target->id)->count());
        $this->assertSame(['vip'], $target->tags()->pluck('name')->all());

        // The source is closed and points at the target, with a public note saying so
        $this->assertSame(TicketStatus::CLOSED, $source->status);
        $this->assertSame($target->id, $source->merged_into_id);
        $note = TicketMessage::where('ticket_id', $source->id)->firstOrFail();
        $this->assertSame('system', $note->author_kind);
        $this->assertSame('public', $note->visibility);

        // The other requester now follows the surviving ticket and was told
        $this->assertTrue(TicketSubscription::where('ticket_id', $target->id)->where('user_id', $this->bob->id)->where('watching', true)->exists());
        Notification::assertSentTo($this->bob, TicketActivity::class, fn (TicketActivity $notification) => $notification->event === 'merged');

        $this->assertFalse(TicketEvent::where('ticket_id', $source->id)->where('type', 'merged_into')->firstOrFail()->internal);
        $this->assertFalse(TicketEvent::where('ticket_id', $target->id)->where('type', 'merged')->firstOrFail()->internal);
    }

    public function test_merging_is_refused_into_itself_another_organization_or_a_closed_ticket(): void
    {
        $source = $this->ticketFor($this->alice);
        $other = $this->ticketFor($this->carol);
        $closed = $this->ticketFor($this->alice);
        $closed->forceFill(['status' => TicketStatus::CLOSED])->save();

        foreach ([$source, $other, $closed] as $target) {
            $this->actingAs($this->admin)
                ->post('/admin/support/tickets/'.$source->number.'/merge', ['target_number' => $target->number])
                ->assertSessionHasErrors('target');
        }

        $this->actingAs($this->admin)
            ->post('/admin/support/tickets/'.$source->number.'/merge', ['target_number' => 999999])
            ->assertSessionHasErrors('target_number');

        $this->assertNull($source->fresh()->merged_into_id);
    }

    public function test_only_staff_can_merge(): void
    {
        $source = $this->ticketFor($this->alice);
        $target = $this->ticketFor($this->bob);

        $this->actingAs($this->alice)
            ->post('/admin/support/tickets/'.$source->number.'/merge', ['target_number' => $target->number])
            ->assertForbidden();

        $this->assertNull($source->fresh()->merged_into_id);
    }

    public function test_a_merged_ticket_is_read_only_for_everyone(): void
    {
        $target = $this->ticketFor($this->alice);
        $source = $this->ticketFor($this->alice);
        $this->service()->merge($source, $target, $this->admin);

        // The customer cannot reply, reopen or rate it, and is pointed at the other ticket
        $this->actingAs($this->alice)->post('/support/tickets/'.$source->number.'/messages', ['body' => '<p>hello?</p>'])->assertForbidden();
        $this->actingAs($this->alice)->get('/support/tickets/'.$source->number)
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.reply', false)
                ->where('can.reopen', false)
                ->where('can.rate', false)
                ->where('ticket.merged_into.reference', $target->reference));

        // Nor can staff change its status or merge it again
        $this->assertSame('status', $this->failure(fn () => $this->service()->changeStatus($source->fresh(), $this->admin, TicketStatus::OPEN, true)));
        $this->assertSame('target', $this->failure(fn () => $this->service()->merge($source->fresh(), $this->ticketFor($this->alice), $this->admin)));
        $this->assertSame('target', $this->failure(fn () => $this->service()->merge($this->ticketFor($this->alice), $source->fresh(), $this->admin)));
    }

    private function failure(callable $action): ?string
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            return implode('|', array_keys($exception->errors()));
        }

        return null;
    }

    // ---- satisfaction rating

    public function test_a_member_can_rate_a_finished_ticket_and_the_latest_rating_stands(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $ticket->forceFill(['status' => TicketStatus::RESOLVED, 'resolved_at' => now()])->save();

        $this->actingAs($this->alice)
            ->post('/support/tickets/'.$ticket->number.'/rating', ['score' => 4, 'comment' => '  Quick and kind  '])
            ->assertRedirect('/support/tickets/'.$ticket->number);

        $ticket->refresh();
        $this->assertSame(4, $ticket->csat_score);
        $this->assertSame('Quick and kind', $ticket->csat_comment);
        $this->assertNotNull($ticket->csat_at);
        // Only staff see ratings in the activity
        $this->assertTrue(TicketEvent::where('ticket_id', $ticket->id)->where('type', 'rated')->firstOrFail()->internal);

        $this->actingAs($this->bob)->post('/support/tickets/'.$ticket->number.'/rating', ['score' => 2, 'comment' => ''])->assertRedirect();

        $this->assertSame(2, $ticket->fresh()->csat_score);
        $this->assertNull($ticket->fresh()->csat_comment);
    }

    public function test_a_poor_rating_alerts_support_and_a_good_one_does_not(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $ticket->forceFill(['status' => TicketStatus::RESOLVED])->save();
        Notification::fake();

        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/rating', ['score' => 5]);
        Notification::assertNothingSent();

        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/rating', ['score' => 1, 'comment' => 'Too slow']);
        Notification::assertSentTo($this->admin, TicketActivity::class, fn (TicketActivity $notification) => $notification->event === 'rating');
    }

    public function test_ratings_are_limited_to_finished_tickets_and_one_to_five(): void
    {
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/rating', ['score' => 5])->assertSessionHasErrors('score');

        $ticket->forceFill(['status' => TicketStatus::RESOLVED])->save();
        foreach ([0, 6, 'x', null] as $score) {
            $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/rating', ['score' => $score])->assertSessionHasErrors('score');
        }

        $this->assertNull($ticket->fresh()->csat_score);
    }

    public function test_other_organizations_cannot_rate_and_staff_see_the_rating(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $ticket->forceFill(['status' => TicketStatus::RESOLVED, 'csat_score' => 3, 'csat_comment' => 'Fine'])->save();

        $this->actingAs($this->carol)->post('/support/tickets/'.$ticket->number.'/rating', ['score' => 1])->assertNotFound();
        $this->assertSame(3, $ticket->fresh()->csat_score);

        $this->actingAs($this->admin)->get('/admin/support/tickets/'.$ticket->number)
            ->assertInertia(fn (Assert $page) => $page->where('ticket.csat.score', 3)->where('ticket.csat.comment', 'Fine'));
    }

    // ---- redacting and deleting

    public function test_redacting_removes_the_content_everywhere_but_leaves_a_placeholder(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $secret = $this->reply($ticket, $this->alice, '<p>my password is hunter2</p>');
        $other = $this->reply($ticket, $this->admin, '<p>noted</p>', staff: true);
        TicketMessageRevision::create(['ticket_message_id' => $secret->id, 'body_html' => '<p>my password is hunter1</p>', 'edited_by' => $this->alice->id]);

        $hook = $this->webhook();
        $delivery = new TicketDelivery;
        $delivery->integration_id = $hook->id;
        $delivery->ticket_id = $ticket->id;
        $delivery->event = 'message.created';
        $delivery->idempotency_key = 'k1';
        $delivery->payload = ['message' => ['id' => $secret->id, 'body_markdown' => 'my password is hunter2', 'body_text' => 'my password is hunter2', 'body_html' => '<p>my password is hunter2</p>', 'attachments' => [['name' => 'x']]]];
        $delivery->save();

        $this->actingAs($this->admin)
            ->post('/admin/support/tickets/'.$ticket->number.'/messages/'.$secret->id.'/redact')
            ->assertRedirect('/admin/support/tickets/'.$ticket->number);

        $secret->refresh();
        $this->assertSame('', $secret->body_html);
        $this->assertSame('', $secret->body_text);
        $this->assertNotNull($secret->redacted_at);
        $this->assertSame(0, TicketMessageRevision::count());
        $this->assertSame('', $delivery->fresh()->payload['message']['body_markdown']);
        $this->assertSame([], $delivery->fresh()->payload['message']['attachments']);
        $this->assertStringContainsString('noted', $other->fresh()->body_html);

        // The customer sees a placeholder, not the words
        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number)
            ->assertDontSee('hunter');
    }

    public function test_only_staff_can_redact_and_only_messages_of_that_ticket(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $message = $this->reply($ticket, $this->alice, '<p>keep</p>');
        $elsewhere = $this->reply($this->ticketFor($this->bob), $this->bob, '<p>other ticket</p>');

        $this->actingAs($this->alice)->post('/admin/support/tickets/'.$ticket->number.'/messages/'.$message->id.'/redact')->assertForbidden();
        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number.'/messages/'.$elsewhere->id.'/redact')->assertNotFound();

        $this->assertNull($message->fresh()->redacted_at);
        $this->assertNull($elsewhere->fresh()->redacted_at);
    }

    public function test_deleting_a_ticket_removes_it_and_everything_attached(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->reply($ticket, $this->alice);
        $other = $this->ticketFor($this->bob);

        $this->actingAs($this->alice)->delete('/admin/support/tickets/'.$ticket->number)->assertForbidden();
        $this->assertNotNull(Ticket::find($ticket->id));

        $this->actingAs($this->admin)->delete('/admin/support/tickets/'.$ticket->number)->assertRedirect('/admin/support/tickets');

        $this->assertNull(Ticket::withTrashed()->find($ticket->id));
        $this->assertSame(0, TicketMessage::withTrashed()->where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, TicketEvent::where('ticket_id', $ticket->id)->count());
        $this->assertNotNull(Ticket::find($other->id));
    }
}
