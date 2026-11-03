<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Services\Tickets\Integrations\DeliveryRunner;
use App\Services\Tickets\Integrations\InboundEvent;
use App\Services\Tickets\Integrations\InboundHandler;
use App\Ticket;
use App\TicketDelivery;
use App\TicketEvent;
use App\TicketExternalLink;
use App\TicketIntegration;
use App\TicketMessage;
use Illuminate\Support\Facades\Http;

/**
 * What the trackers (GitHub, GitLab) send back: a comment or a closed issue
 * lands on the ticket as a staff-only note.
 */
class InboundWebhookTest extends TicketsTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private TicketIntegration $github;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->github = $this->integration('github', [
            'token' => 'ghp_secret', 'repository' => 'acme/platform', 'webhook_secret' => 'whsec', 'inbound_token' => self::TOKEN,
        ], ['options' => []]);
        $this->ticket = $this->ticketFor($this->alice);
        $this->link($this->ticket, $this->github, '42');
    }

    private function link(Ticket $ticket, TicketIntegration $integration, string $issue): TicketExternalLink
    {
        $link = new TicketExternalLink;
        $link->ticket_id = $ticket->id;
        $link->integration_id = $integration->id;
        $link->external_id = $issue;
        $link->external_key = '#'.$issue;
        $link->external_state = 'open';
        $link->save();

        return $link;
    }

    private function url(?TicketIntegration $integration = null, string $token = self::TOKEN): string
    {
        return '/webhooks/tickets/'.($integration ?? $this->github)->id.'/'.$token;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function githubCall(array $payload, string $event = 'issue_comment', ?string $signature = null, string $delivery = 'd1', ?string $url = null)
    {
        $body = json_encode($payload);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => $delivery,
            'HTTP_X_HUB_SIGNATURE_256' => $signature ?? 'sha256='.hash_hmac('sha256', $body, 'whsec'),
        ];

        return $this->call('POST', $url ?? $this->url(), [], [], [], $server, $body);
    }

    private function comment(string $text = 'Fixed in 1.2', string $user = 'dev'): array
    {
        return ['action' => 'created', 'issue' => ['number' => 42], 'comment' => ['body' => $text, 'user' => ['login' => $user]], 'repository' => ['full_name' => 'acme/platform']];
    }

    // ---- the endpoint

    public function test_a_signed_comment_becomes_a_staff_only_note(): void
    {
        $this->githubCall($this->comment('Fixed in 1.2 <script>alert(1)</script>'))->assertNoContent();

        $note = TicketMessage::where('ticket_id', $this->ticket->id)->where('author_kind', 'system')->firstOrFail();
        $this->assertSame('internal', $note->visibility);
        $this->assertStringContainsString('dev', $note->body_html);
        $this->assertStringContainsString('Fixed in 1.2', $note->body_html);
        $this->assertStringNotContainsString('<script>', $note->body_html);

        // The customer never sees it
        $this->assertSame(1, $this->ticket->publicMessages()->count());
    }

    public function test_the_same_delivery_twice_is_noted_once(): void
    {
        $this->githubCall($this->comment(), delivery: 'same')->assertNoContent();
        $this->githubCall($this->comment('Again'), delivery: 'same')->assertNoContent();

        $this->assertSame(1, TicketMessage::where('ticket_id', $this->ticket->id)->where('author_kind', 'system')->count());
    }

    public function test_the_address_must_be_right(): void
    {
        $this->githubCall($this->comment(), url: $this->url(token: str_repeat('b', 40)))->assertNotFound();
        $this->githubCall($this->comment(), url: '/webhooks/tickets/9999/'.self::TOKEN)->assertNotFound();
        // A token that is not even shaped like one never reaches the controller
        $this->githubCall($this->comment(), url: '/webhooks/tickets/'.$this->github->id.'/short')->assertNotFound();

        $this->github->forceFill(['enabled' => false])->save();
        $this->githubCall($this->comment())->assertNotFound();

        $this->assertSame(0, TicketMessage::where('author_kind', 'system')->count());
    }

    public function test_the_feature_flag_closes_the_endpoint(): void
    {
        config(['toggle.flags.tickets' => false]);

        $this->githubCall($this->comment())->assertNotFound();
    }

    public function test_a_driver_that_cannot_receive_events_has_no_endpoint(): void
    {
        $hook = $this->webhook();
        $hook->config = array_merge($hook->config, ['inbound_token' => self::TOKEN]);
        $hook->save();

        $this->githubCall($this->comment(), url: $this->url($hook))->assertNotFound();
    }

    public function test_the_signature_must_be_right(): void
    {
        $this->githubCall($this->comment(), signature: 'sha256='.str_repeat('0', 64))->assertUnauthorized();
        $this->githubCall($this->comment(), signature: 'nonsense')->assertUnauthorized();

        $body = json_encode($this->comment());
        $this->call('POST', $this->url(), [], [], [], ['HTTP_X_GITHUB_EVENT' => 'issue_comment'], $body)->assertUnauthorized();

        // No secret set up means nothing can be trusted
        $config = $this->github->config;
        $config['webhook_secret'] = '';
        $this->github->config = $config;
        $this->github->save();
        $this->githubCall($this->comment())->assertUnauthorized();

        $this->assertSame(0, TicketMessage::where('author_kind', 'system')->count());
    }

    public function test_oversized_bodies_are_refused(): void
    {
        $padding = str_repeat('x', 1_100_000);
        $this->githubCall($this->comment($padding))->assertStatus(413);
    }

    public function test_a_ping_from_the_tracker_is_acknowledged(): void
    {
        // The tracker's test button; nothing to do with it, but it should not look broken
        $this->githubCall(['zen' => 'Keep it logically awesome.'], event: 'ping')->assertNoContent();
    }

    public function test_other_events_repositories_and_pull_requests_are_ignored_quietly(): void
    {
        $this->githubCall(['zen' => 'x'], event: 'ping')->assertNoContent();
        $this->githubCall(['action' => 'created', 'issue' => ['number' => 42], 'comment' => ['body' => 'x', 'user' => ['login' => 'dev']], 'repository' => ['full_name' => 'other/repo']])->assertNoContent();
        $this->githubCall(['action' => 'created', 'issue' => ['number' => 42, 'pull_request' => []], 'comment' => ['body' => 'x', 'user' => ['login' => 'dev']], 'repository' => ['full_name' => 'acme/platform']], delivery: 'd2')->assertNoContent();
        $this->githubCall(['action' => 'labeled', 'issue' => ['number' => 42], 'repository' => ['full_name' => 'acme/platform']], event: 'issues', delivery: 'd3')->assertNoContent();

        $this->assertSame(0, TicketMessage::where('author_kind', 'system')->count());
    }

    public function test_gitlab_needs_its_token(): void
    {
        $gitlab = $this->integration('gitlab', ['token' => 'glpat', 'project' => 'acme/group/platform', 'webhook_secret' => 'tok', 'inbound_token' => self::TOKEN], ['options' => []]);
        $this->link($this->ticket, $gitlab, '7');
        $body = json_encode(['object_kind' => 'note', 'project' => ['path_with_namespace' => 'acme/group/platform'], 'user' => ['username' => 'dev'], 'object_attributes' => ['noteable_type' => 'Issue', 'note' => 'on it'], 'issue' => ['iid' => 7]]);

        $this->call('POST', $this->url($gitlab), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GITLAB_TOKEN' => 'nope'], $body)->assertUnauthorized();
        $this->call('POST', $this->url($gitlab), [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertUnauthorized();
        $this->assertSame(0, TicketMessage::where('author_kind', 'system')->count());

        $this->call('POST', $this->url($gitlab), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GITLAB_TOKEN' => 'tok'], $body)->assertNoContent();
        $this->assertSame(1, TicketMessage::where('ticket_id', $this->ticket->id)->where('author_kind', 'system')->count());
    }

    // ---- what the handler does with an event

    private function handler(): InboundHandler
    {
        return app(InboundHandler::class);
    }

    public function test_our_own_comments_coming_back_are_ignored(): void
    {
        $result = $this->handler()->handle($this->github, new InboundEvent('comment', '42', 'c2', null, 'bot', "**Support** wrote:\n\nhi\n\n<!-- kcp:ticket -->"));

        $this->assertSame('ignored', $result);
        $this->assertSame(0, TicketMessage::where('author_kind', 'system')->count());
    }

    public function test_an_unknown_issue_is_ignored(): void
    {
        $this->assertSame('ignored', $this->handler()->handle($this->github, new InboundEvent('comment', '999', 'c3', null, 'dev', 'x')));
    }

    public function test_comments_can_be_switched_off(): void
    {
        $this->github->options = ['inbound_comments' => false];
        $this->github->save();

        $this->assertSame('ignored', $this->handler()->handle($this->github, new InboundEvent('comment', '42', 'c9', null, 'dev', 'nope')));
        $this->assertSame(0, TicketMessage::where('author_kind', 'system')->count());
    }

    public function test_closing_the_issue_is_noted_and_changes_the_ticket_only_if_asked(): void
    {
        $this->assertSame('noted', $this->handler()->handle($this->github, new InboundEvent('state', '42', 's1', 'closed')));

        $this->assertSame(TicketStatus::OPEN, $this->ticket->fresh()->status);
        $this->assertSame('closed', TicketExternalLink::where('ticket_id', $this->ticket->id)->first()->external_state);
        // The same state again says nothing new
        $this->assertSame('ignored', $this->handler()->handle($this->github, new InboundEvent('state', '42', 's2', 'closed')));
    }

    public function test_with_resolve_on_the_issue_drives_the_ticket_as_the_system(): void
    {
        $this->github->options = ['inbound_resolve' => true];
        $this->github->save();
        TicketExternalLink::where('ticket_id', $this->ticket->id)->update(['external_state' => 'closed']);

        // Reopening the issue leaves a ticket that is already open alone
        $this->assertSame('noted', $this->handler()->handle($this->github, new InboundEvent('state', '42', 's3', 'open')));
        $this->assertSame(TicketStatus::OPEN, $this->ticket->fresh()->status);

        // Closing it resolves the ticket, and nobody in particular did that
        $this->assertSame('changed', $this->handler()->handle($this->github, new InboundEvent('state', '42', 's4', 'closed')));
        $this->assertSame(TicketStatus::RESOLVED, $this->ticket->fresh()->status);
        $event = TicketEvent::where('ticket_id', $this->ticket->id)->where('type', 'status_changed')->latest('id')->firstOrFail();
        $this->assertNull($event->actor_id);

        // Reopening it reopens the ticket
        $this->assertSame('changed', $this->handler()->handle($this->github, new InboundEvent('state', '42', 's5', 'open')));
        $this->assertSame(TicketStatus::OPEN, $this->ticket->fresh()->status);
    }

    public function test_status_changes_caused_by_the_tracker_are_not_echoed_back(): void
    {
        $this->github->options = ['inbound_resolve' => true];
        $this->github->save();
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->handler()->handle($this->github, new InboundEvent('state', '42', 's4', 'closed'));

        // What was queued for the tracker is settled without a request: the issue is already in that state
        $deliveries = TicketDelivery::where('integration_id', $this->github->id)->where('event', 'status.changed')->get();
        $this->assertNotEmpty($deliveries);
        foreach ($deliveries as $delivery) {
            app(DeliveryRunner::class)->run($delivery->id);
            $this->assertSame('delivered', $delivery->fresh()->status);
        }

        Http::assertNothingSent();
    }

    public function test_a_merged_ticket_is_never_changed_by_its_issue(): void
    {
        $merged = $this->ticketFor($this->alice);
        $merged->forceFill(['merged_into_id' => $this->ticket->id, 'status' => TicketStatus::CLOSED])->save();
        $this->link($merged, $this->github, '77');
        $this->github->options = ['inbound_resolve' => true];
        $this->github->save();

        $this->handler()->handle($this->github, new InboundEvent('state', '77', 's7', 'open'));

        $this->assertSame(TicketStatus::CLOSED, $merged->fresh()->status);
    }
}
