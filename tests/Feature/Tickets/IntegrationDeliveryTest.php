<?php

namespace Tests\Feature\Tickets;

use App\Console\Calls\MaintainTicketDeliveries;
use App\Enums\TicketStatus;
use App\Jobs\Tickets\DeliverTicketIntegration;
use App\Notifications\Tickets\IntegrationDisabled;
use App\Services\Tickets\Integrations\DeliveryRunner;
use App\Services\Tickets\Integrations\IntegrationDispatcher;
use App\TicketAttachment;
use App\TicketDelivery;
use App\TicketEvent;
use App\TicketExternalLink;
use App\TicketIntegration;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

class IntegrationDeliveryTest extends TicketsTestCase
{
    private function runner(): DeliveryRunner
    {
        return app(DeliveryRunner::class);
    }

    private function run(TicketDelivery $delivery): ?int
    {
        return $this->runner()->run($delivery->id);
    }

    private function latest(): TicketDelivery
    {
        return TicketDelivery::latest('id')->firstOrFail();
    }

    // ---- what gets queued

    public function test_a_webhook_is_told_about_the_events_it_subscribed_to(): void
    {
        $this->webhook(['ticket.created', 'message.created']);
        $ticket = $this->ticketFor($this->alice);

        $this->assertSame(['ticket.created'], TicketDelivery::pluck('event')->all());
        Queue::assertPushed(DeliverTicketIntegration::class, 1);

        $this->reply($ticket, $this->admin, '<p>Hello</p>', staff: true);
        $this->service()->changeStatus($ticket, $this->admin, TicketStatus::RESOLVED, true);

        // status.changed was not subscribed to
        $this->assertSame(['ticket.created', 'message.created'], TicketDelivery::orderBy('id')->pluck('event')->all());
    }

    public function test_each_kind_of_event_is_delivered(): void
    {
        $this->webhook();
        $ticket = $this->ticketFor($this->alice);

        $this->reply($ticket, $this->admin, '<p>Hello</p>', staff: true);
        $this->service()->changeStatus($ticket, $this->admin, TicketStatus::ON_HOLD, true);
        $this->service()->assign($ticket, 'support', $this->admin, $this->admin);
        $this->service()->logTime($ticket, $this->admin, 30);

        $this->assertEqualsCanonicalizing(
            ['ticket.created', 'message.created', 'status.changed', 'assigned', 'time.logged'],
            TicketDelivery::pluck('event')->all(),
        );
    }

    public function test_internal_notes_are_never_sent(): void
    {
        $this->webhook();
        $this->github();
        $ticket = $this->ticketFor($this->alice);
        TicketDelivery::query()->delete();

        $this->reply($ticket, $this->admin, '<p>Our secret plan</p>', staff: true, internal: true);

        $this->assertSame(0, TicketDelivery::count());
        $this->assertSame(0, TicketDelivery::where('payload', 'like', '%secret plan%')->count());
    }

    public function test_the_payload_carries_what_is_allowed_out_and_no_more(): void
    {
        $this->webhook();
        $ticket = $this->ticketFor($this->alice, ['subject' => 'Printer on fire', 'body' => '<p>Please <strong>help</strong></p>']);

        $payload = $this->latest()->payload;

        $this->assertSame('ticket.created', $payload['event']);
        $this->assertSame($ticket->reference, $payload['ticket']['reference']);
        $this->assertSame('Printer on fire', $payload['ticket']['subject']);
        $this->assertSame(url('/admin/support/tickets/'.$ticket->number), $payload['ticket']['url']);
        $this->assertSame($this->orgA->name, $payload['ticket']['organization']['name']);
        $this->assertSame('Please **help**', $payload['message']['body_markdown']);
        $this->assertSame(['kind' => 'customer', 'name' => null], $payload['message']['author']);
        // People are left out unless the integration was told to include them
        $this->assertArrayNotHasKey('requester', $payload['ticket']);
        $this->assertStringNotContainsString($this->alice->email, json_encode($payload));
        $this->assertStringNotContainsString($this->alice->name, json_encode($payload));
    }

    public function test_names_are_included_only_when_the_integration_asks_for_them(): void
    {
        $this->webhook(['ticket.created'], ['options' => ['include_requester' => true]]);
        $this->ticketFor($this->alice);

        $payload = $this->latest()->payload;

        $this->assertSame(['name' => $this->alice->name, 'email' => $this->alice->email], $payload['ticket']['requester']);
        $this->assertSame($this->alice->name, $payload['message']['author']['name']);
    }

    public function test_attachments_are_listed_but_never_sent(): void
    {
        $this->webhook(['ticket.created']);
        $draft = new TicketAttachment;
        $draft->organization_id = $this->orgA->id;
        $draft->uploader_id = $this->alice->id;
        $draft->disk = 'tickets';
        $draft->path = 'x/y';
        $draft->original_name = 'screenshot.png';
        $draft->mime = 'image/png';
        $draft->size = 4096;
        $draft->sha256 = str_repeat('a', 64);
        $draft->save();

        $this->ticketFor($this->alice, ['attachment_ids' => [$draft->id]]);

        $files = $this->latest()->payload['message']['attachments'];
        $this->assertSame([['name' => 'screenshot.png', 'size' => 4096, 'mime' => 'image/png']], $files);
        $this->assertStringNotContainsString('x/y', json_encode($this->latest()->payload));
    }

    public function test_the_same_occurrence_is_only_ever_queued_once(): void
    {
        $this->webhook();
        $ticket = $this->ticketFor($this->alice);

        app(IntegrationDispatcher::class)->dispatch('ticket.created', $ticket, [], $ticket->id);

        $this->assertSame(1, TicketDelivery::where('event', 'ticket.created')->count());
    }

    public function test_filters_limit_which_tickets_are_sent(): void
    {
        $this->webhook(['ticket.created'], ['filters' => ['types' => ['feature']]]);

        $this->ticketFor($this->alice, ['type' => 'bug']);
        $this->assertSame(0, TicketDelivery::count());

        $this->ticketFor($this->alice, ['type' => 'feature']);
        $this->assertSame(1, TicketDelivery::count());
    }

    public function test_a_switched_off_integration_receives_nothing_new(): void
    {
        $this->webhook(['ticket.created'], ['enabled' => false]);

        $this->ticketFor($this->alice);

        $this->assertSame(0, TicketDelivery::count());
    }

    public function test_a_broken_integration_never_breaks_the_ticket(): void
    {
        $this->webhook(['ticket.created']);
        // A driver that has been uninstalled
        TicketIntegration::query()->update(['driver' => 'no_such_driver']);

        $ticket = $this->ticketFor($this->alice);

        $this->assertNotNull($ticket->fresh());
        $this->assertSame(0, TicketDelivery::count());
    }

    public function test_an_integration_that_throws_cannot_take_the_ticket_down_with_it(): void
    {
        $dispatcher = \Mockery::mock(IntegrationDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andThrow(new \RuntimeException('integration exploded'));
        $this->app->instance(IntegrationDispatcher::class, $dispatcher);

        $ticket = $this->ticketFor($this->alice);
        $this->reply($ticket, $this->admin, '<p>Still works</p>', staff: true);

        $this->assertSame(2, $ticket->messages()->count());
    }

    // ---- delivering

    public function test_a_successful_delivery_is_recorded(): void
    {
        Http::fake(['hooks.example.test/*' => Http::response(['ok' => true, 'token' => 'abc123'], 200)]);
        $integration = $this->webhook(['ticket.created'], ['consecutive_failures' => 3]);
        $this->ticketFor($this->alice);
        $delivery = $this->latest();

        $this->assertNull($this->run($delivery));

        $delivery->refresh();
        $this->assertSame(['delivered', 1, 200], [$delivery->status, $delivery->attempts, $delivery->response_code]);
        $this->assertNotNull($delivery->delivered_at);
        // Anything that looks like a secret in the answer is masked in the log
        $this->assertStringNotContainsString('abc123', (string) $delivery->response);
        $this->assertStringContainsString('"ok":true', (string) $delivery->response);

        $integration->refresh();
        $this->assertSame(0, $integration->consecutive_failures);
        $this->assertNotNull($integration->last_success_at);

        // Nothing happens if it is run again
        $this->assertNull($this->run($delivery));
        Http::assertSentCount(1);
    }

    public function test_the_request_carries_a_signature_the_receiver_can_check(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->webhook(['ticket.created']);
        $this->ticketFor($this->alice);
        $delivery = $this->latest();

        $this->run($delivery);

        Http::assertSent(function (Request $request) use ($delivery) {
            preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $request->header('X-KCP-Signature')[0], $m);

            return $request->url() === 'https://hooks.example.test/in'
                && $request->header('X-KCP-Event')[0] === 'ticket.created'
                && $request->header('X-KCP-Delivery')[0] === (string) $delivery->id
                && hash_equals(hash_hmac('sha256', $m[1].'.'.$request->body(), 'sekrit'), $m[2])
                && json_decode($request->body(), true)['ticket']['reference'] === $delivery->payload['ticket']['reference'];
        });
    }

    public function test_temporary_trouble_is_retried_with_growing_waits_then_given_up(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $this->webhook(['ticket.created']);
        $this->ticketFor($this->alice);
        $delivery = $this->latest();

        $waits = [];
        for ($i = 0; $i < 8; $i++) {
            $waits[] = $this->run($delivery);
        }

        $this->assertSame([30, 120, 600, 1800, 3600, 7200, 14400, null], $waits);
        $delivery->refresh();
        $this->assertSame(['failed', 8, 500], [$delivery->status, $delivery->attempts, $delivery->response_code]);
        $this->assertStringContainsString('500', $delivery->error);
    }

    public function test_a_busy_receiver_decides_how_long_to_wait(): void
    {
        Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '90'])]);
        $this->webhook(['ticket.created']);
        $this->ticketFor($this->alice);

        $this->assertSame(90, $this->run($this->latest()));
    }

    public function test_a_refusal_is_not_retried(): void
    {
        Http::fake(['*' => Http::response('forbidden', 403)]);
        $this->webhook(['ticket.created']);
        $this->ticketFor($this->alice);
        $delivery = $this->latest();

        $this->assertNull($this->run($delivery));

        $this->assertSame(['failed', 1], [$delivery->fresh()->status, $delivery->fresh()->attempts]);
    }

    public function test_an_integration_that_keeps_failing_is_switched_off_and_staff_are_told(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $integration = $this->webhook(['ticket.created']);
        $this->ticketFor($this->alice);
        $first = $this->latest();
        $this->ticketFor($this->alice);
        $second = $this->latest();
        Notification::fake();

        // A delivery gives up after 8 tries, so the 10 failures come from two of them
        for ($i = 0; $i < 8; $i++) {
            $this->run($first);
        }
        $this->assertTrue($integration->fresh()->enabled);
        $this->run($second);
        $this->run($second);

        $integration->refresh();
        $this->assertFalse($integration->enabled);
        $this->assertStringContainsString('10 failures in a row', $integration->disabled_reason);
        Notification::assertSentTo($this->admin, IntegrationDisabled::class, 1);
        Notification::assertNotSentTo($this->alice, IntegrationDisabled::class);

        // Its remaining waiting delivery is stopped rather than retried against a dead endpoint
        $this->assertNull($this->run($second));
        $this->assertSame('failed', $second->fresh()->status);
    }

    public function test_a_waiting_delivery_for_a_switched_off_integration_stops_and_can_be_redelivered_once_fixed(): void
    {
        $integration = $this->webhook(['ticket.created']);
        $this->ticketFor($this->alice);
        $delivery = $this->latest();
        $integration->forceFill(['enabled' => false])->save();

        $this->assertNull($this->run($delivery));
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertStringContainsString('switched off', $delivery->fresh()->error);

        Http::fake(['*' => Http::response('ok', 200)]);
        $integration->forceFill(['enabled' => true])->save();
        $delivery->forceFill(['status' => 'pending', 'attempts' => 0])->save();

        $this->assertNull($this->run($delivery));
        $this->assertSame('delivered', $delivery->fresh()->status);
    }

    public function test_deliveries_for_one_ticket_go_in_order(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->webhook(['ticket.created', 'message.created']);
        $ticket = $this->ticketFor($this->alice);
        $this->reply($ticket, $this->admin, '<p>Hello</p>', staff: true);
        [$first, $second] = TicketDelivery::orderBy('id')->get()->all();

        $this->assertSame(30, $this->run($second));
        $this->assertNull($this->run($first));
        $this->assertNull($this->run($second));
        $this->assertSame('delivered', $second->fresh()->status);
    }

    public function test_the_queued_job_puts_itself_back_for_the_time_the_runner_names(): void
    {
        $this->webhook(['ticket.created', 'message.created']);
        $ticket = $this->ticketFor($this->alice);
        $this->reply($ticket, $this->admin, '<p>Hello</p>', staff: true);
        $second = TicketDelivery::orderBy('id')->get()->last();

        $job = (new DeliverTicketIntegration($second->id))->withFakeQueueInteractions();
        $job->handle($this->runner());

        $job->assertReleased(delay: 30);
    }

    public function test_a_finished_job_is_not_put_back(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->webhook(['ticket.created']);
        $this->ticketFor($this->alice);

        $job = (new DeliverTicketIntegration($this->latest()->id))->withFakeQueueInteractions();
        $job->handle($this->runner());

        $job->assertNotReleased();
    }

    // ---- trackers

    public function test_auto_create_opens_an_issue_and_links_it(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['number' => 42, 'html_url' => 'https://github.com/acme/platform/issues/42'], 201)]);
        $integration = $this->github();
        $ticket = $this->ticketFor($this->alice, ['subject' => 'Cannot log in']);

        $this->assertNull($this->run($this->latest()));

        $link = TicketExternalLink::firstOrFail();
        $this->assertSame([$ticket->id, $integration->id, '42', '#42', 'https://github.com/acme/platform/issues/42', 'open'], [
            $link->ticket_id, $link->integration_id, $link->external_id, $link->external_key, $link->external_url, $link->external_state,
        ]);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.github.com/repos/acme/platform/issues'
            && $r['title'] === '['.$ticket->reference.'] Cannot log in'
            && $r->hasHeader('Authorization', 'Bearer ghp_secret'));
        $event = TicketEvent::where('ticket_id', $ticket->id)->where('type', 'issue_linked')->first();
        $this->assertTrue($event->internal);
        $this->assertSame('#42', $event->context['key']);
    }

    public function test_a_tracker_follows_the_conversation_state_and_time_of_a_linked_ticket(): void
    {
        Http::fake(['api.github.com/*' => Http::sequence()
            ->push(['number' => 42, 'html_url' => 'https://github.com/acme/platform/issues/42'], 201)
            ->push(['id' => 1], 201)
            ->push([], 200)
            ->push(['id' => 2], 201)]);
        $this->github();
        $ticket = $this->ticketFor($this->alice);

        // Everything queued behind the issue waits for it
        $this->reply($ticket, $this->admin, '<p>We are on it</p>', staff: true);
        $this->service()->changeStatus($ticket, $this->admin, TicketStatus::RESOLVED, true);
        $this->service()->logTime($ticket, $this->admin, 90, 'diagnosis');
        $deliveries = TicketDelivery::orderBy('id')->get();
        $this->assertSame(['ticket.created', 'message.created', 'status.changed', 'time.logged'], $deliveries->pluck('event')->all());

        $this->assertSame(30, $this->run($deliveries[1]));
        foreach ($deliveries as $delivery) {
            $this->assertNull($this->run($delivery));
        }

        $this->assertSame(['delivered', 'delivered', 'delivered', 'delivered'], TicketDelivery::orderBy('id')->pluck('status')->all());
        $this->assertSame('closed', TicketExternalLink::first()->external_state);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.github.com/repos/acme/platform/issues/42/comments' && str_contains($r['body'], 'We are on it'));
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['state'] === 'closed');
        Http::assertSent(fn (Request $r) => str_contains($r['body'] ?? '', '1h 30m'));
    }

    public function test_a_tracker_says_nothing_about_a_ticket_with_no_issue(): void
    {
        $this->github(['auto_create' => false]);
        $ticket = $this->ticketFor($this->alice);

        $this->reply($ticket, $this->admin, '<p>Hello</p>', staff: true);
        $this->service()->changeStatus($ticket, $this->admin, TicketStatus::RESOLVED, true);

        $this->assertSame(0, TicketDelivery::count());
    }

    public function test_closing_an_already_closed_issue_does_nothing(): void
    {
        Http::fake(['api.github.com/*' => Http::sequence()->push(['number' => 1, 'html_url' => 'https://x/1'], 201)->push([], 200)]);
        $this->github();
        $ticket = $this->ticketFor($this->alice);
        $this->run($this->latest());

        $this->service()->changeStatus($ticket, $this->admin, TicketStatus::RESOLVED, true);
        $this->run($this->latest());
        $this->service()->changeStatus($ticket, $this->admin, TicketStatus::CLOSED, true);
        $closed = $this->latest();
        $this->run($closed);

        // Resolved then closed: GitHub is only told once
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $patches = 0;
        Http::assertSent(function (Request $r) use (&$patches) {
            $patches += $r->method() === 'PATCH' ? 1 : 0;

            return true;
        });
        $this->assertSame(1, $patches);
        $this->assertStringContainsString('already', (string) $closed->fresh()->response);
    }

    public function test_state_sync_and_time_sync_can_be_switched_off(): void
    {
        Http::fake(['*' => Http::response(['number' => 1, 'html_url' => 'https://x/1'], 201)]);
        $this->github(['auto_create' => true, 'sync_state' => false, 'sync_time' => false]);
        $ticket = $this->ticketFor($this->alice);
        $this->run($this->latest());
        TicketDelivery::query()->delete();

        $this->service()->changeStatus($ticket, $this->admin, TicketStatus::RESOLVED, true);
        $this->service()->logTime($ticket, $this->admin, 10);

        $this->assertSame(0, TicketDelivery::count());
    }

    public function test_staff_can_open_the_issue_from_the_ticket_page(): void
    {
        Http::fake(['gitlab.com/*' => Http::response(['iid' => 3, 'web_url' => 'https://gitlab.com/a/b/-/issues/3'], 201)]);
        $integration = $this->integration('gitlab', ['token' => 't', 'project' => 'a/b', 'base_url' => 'https://gitlab.com'], ['options' => []]);
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number.'/issues', ['integration_id' => $integration->id])->assertRedirect()->assertSessionHas('success');
        $delivery = $this->latest();
        $this->assertSame('issue.create', $delivery->event);

        // Asking again while it is on its way does not open a second one
        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number.'/issues', ['integration_id' => $integration->id])->assertSessionHas('error');
        $this->assertSame(1, TicketDelivery::where('event', 'issue.create')->count());

        $this->run($delivery);
        $this->assertSame('#3', TicketExternalLink::first()->external_key);

        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number.'/issues', ['integration_id' => $integration->id])->assertSessionHas('error');
    }

    public function test_a_webhook_cannot_be_asked_to_open_an_issue(): void
    {
        $webhook = $this->webhook();
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number.'/issues', ['integration_id' => $webhook->id])
            ->assertSessionHasErrors('integration_id');
    }

    public function test_customers_cannot_open_issues(): void
    {
        $github = $this->github(['auto_create' => false]);
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->post('/admin/support/tickets/'.$ticket->number.'/issues', ['integration_id' => $github->id])->assertForbidden();
        $this->assertSame(0, TicketDelivery::count());
    }

    public function test_the_ticket_page_shows_links_targets_and_recent_deliveries_to_staff_only(): void
    {
        Http::fake(['*' => Http::response(['number' => 1, 'html_url' => 'https://github.com/acme/platform/issues/1'], 201)]);
        $this->github();
        $gitlab = $this->integration('gitlab', ['token' => 't', 'project' => 'a/b'], ['options' => []]);
        $ticket = $this->ticketFor($this->alice);
        $this->run($this->latest());

        $this->actingAs($this->admin)->get('/admin/support/tickets/'.$ticket->number)
            ->assertInertia(fn ($page) => $page
                ->where('links.0.key', '#1')
                ->where('links.0.url', 'https://github.com/acme/platform/issues/1')
                ->where('issue_targets', [['id' => $gitlab->id, 'name' => $gitlab->name]])
                ->where('deliveries.0.status', 'delivered'));

        // Customers never see any of it
        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number)
            ->assertInertia(fn ($page) => $page->missing('links')->missing('issue_targets')->missing('deliveries')->missing('time_entries'))
            ->assertDontSee('github.com/acme');
    }

    // ---- housekeeping

    public function test_lost_deliveries_are_put_back_on_the_queue_and_old_history_is_forgotten(): void
    {
        $this->webhook(['ticket.created']);
        $this->ticketFor($this->alice);
        $stuck = $this->latest();
        $stuck->forceFill(['created_at' => now()->subHour()])->save();

        $this->ticketFor($this->alice);
        $finished = $this->latest();
        $finished->forceFill(['status' => 'delivered', 'created_at' => now()->subDays(40)])->save();
        $this->ticketFor($this->alice);
        $recentDelivery = $this->latest();
        $recentDelivery->forceFill(['status' => 'delivered'])->save();
        Queue::fake();

        (new MaintainTicketDeliveries)();

        Queue::assertPushed(DeliverTicketIntegration::class, fn ($job) => $job->deliveryId === $stuck->id);
        Queue::assertPushed(DeliverTicketIntegration::class, 1);
        $this->assertNull(TicketDelivery::find($finished->id));
        $this->assertNotNull(TicketDelivery::find($recentDelivery->id));
        $this->assertNotNull(TicketDelivery::find($stuck->id));
    }
}
