<?php

namespace Tests\Feature\Tickets;

use App\Services\Tickets\CannedResponseRenderer;
use App\TicketCannedResponse;
use App\User;
use Inertia\Testing\AssertableInertia as Assert;

class CannedResponsesTest extends TicketsTestCase
{
    private function response(string $title, string $body, string $scope, User $owner): TicketCannedResponse
    {
        $response = new TicketCannedResponse(['title' => $title]);
        $response->body_html = $body;
        $response->scope = $scope;
        $response->owner_id = $owner->id;
        $response->save();

        return $response;
    }

    public function test_customers_cannot_touch_canned_responses(): void
    {
        $response = $this->response('Hello', '<p>Hi</p>', 'shared', $this->admin);
        $ticket = $this->ticketFor($this->alice);

        $this->actingAs($this->alice)->get('/admin/support/canned')->assertForbidden();
        $this->actingAs($this->alice)->post('/admin/support/canned', ['title' => 'x', 'body' => '<p>x</p>', 'scope' => 'shared'])->assertForbidden();
        $this->actingAs($this->alice)->post('/admin/support/tickets/'.$ticket->number.'/canned/'.$response->id)->assertForbidden();
    }

    public function test_staff_create_edit_and_delete_responses(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/support/canned', ['title' => 'Greeting', 'body' => '<p>Hello <script>alert(1)</script>{{requester.name}}</p>', 'scope' => 'shared'])
            ->assertSessionHasNoErrors();

        $response = TicketCannedResponse::firstOrFail();
        $this->assertSame($this->admin->id, $response->owner_id);
        // The template is cleaned when it is saved, not only when it is used
        $this->assertStringNotContainsString('script', $response->body_html);
        $this->assertStringContainsString('{{requester.name}}', $response->body_html);

        $this->actingAs($this->admin)
            ->put('/admin/support/canned/'.$response->id, ['title' => 'Greeting v2', 'body' => '<p>Hi</p>', 'scope' => 'personal'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Greeting v2', $response->fresh()->title);
        $this->assertSame('personal', $response->fresh()->scope);

        $this->actingAs($this->admin)->delete('/admin/support/canned/'.$response->id)->assertRedirect();
        $this->assertNull(TicketCannedResponse::find($response->id));
    }

    public function test_the_form_is_validated(): void
    {
        foreach ([['title' => ''], ['body' => ''], ['scope' => 'public'], ['title' => str_repeat('a', 121)]] as $bad) {
            $this->actingAs($this->admin)
                ->post('/admin/support/canned', array_merge(['title' => 'T', 'body' => '<p>b</p>', 'scope' => 'shared'], $bad))
                ->assertSessionHasErrors(array_key_first($bad));
        }

        $this->assertSame(0, TicketCannedResponse::count());
    }

    public function test_you_see_shared_responses_and_your_own_never_somebody_elses_personal_ones(): void
    {
        $sam = $this->staffMember('sam');
        $this->response('Shared', '<p>x</p>', 'shared', $sam);
        $this->response('Mine', '<p>x</p>', 'personal', $this->admin);
        $theirs = $this->response('Theirs', '<p>x</p>', 'personal', $sam);

        $this->actingAs($this->admin)->get('/admin/support/canned')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tickets/CannedResponses')
                ->where('responses', fn ($responses) => collect($responses)->pluck('title')->sort()->values()->all() === ['Mine', 'Shared']));

        // Somebody else's personal response does not exist as far as I can tell
        $this->actingAs($this->admin)->put('/admin/support/canned/'.$theirs->id, ['title' => 'Hijacked', 'body' => '<p>x</p>', 'scope' => 'shared'])->assertNotFound();
        $this->actingAs($this->admin)->delete('/admin/support/canned/'.$theirs->id)->assertNotFound();
        $ticket = $this->ticketFor($this->alice);
        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number.'/canned/'.$theirs->id)->assertNotFound();

        $this->assertSame('Theirs', $theirs->fresh()->title);
    }

    public function test_a_response_is_filled_in_for_a_ticket_without_being_sent(): void
    {
        $ticket = $this->ticketFor($this->alice, ['subject' => 'Cannot log in']);
        $response = $this->response('Hello', '<p>Hi {{requester.name}} at {{ org.name }}, this is {{agent.name}} about {{ticket.reference}}: {{ticket.subject}}. {{unknown.thing}}</p>', 'shared', $this->admin);

        $html = $this->actingAs($this->admin)
            ->post('/admin/support/tickets/'.$ticket->number.'/canned/'.$response->id)
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('Hi Alice at Acme', $html);
        $this->assertStringContainsString('this is '.$this->admin->name, $html);
        $this->assertStringContainsString($ticket->reference.': Cannot log in', $html);
        // A typo stays visible so it gets noticed
        $this->assertStringContainsString('{{unknown.thing}}', $html);
        // Nothing was posted
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_values_typed_by_people_cannot_inject_markup(): void
    {
        $ticket = $this->ticketFor($this->alice, ['subject' => '<img src=x onerror=alert(1)> & "q"']);
        $response = $this->response('Subject', '<p>About {{ticket.subject}}</p>', 'shared', $this->admin);

        $html = app(CannedResponseRenderer::class)->render($response, $ticket, $this->admin);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function test_who_may_change_what(): void
    {
        $sam = $this->staffMember('sam');
        $shared = $this->response('Shared', '<p>x</p>', 'shared', $this->admin);
        $mine = $this->response('Mine', '<p>x</p>', 'personal', $this->admin);

        $this->assertTrue($shared->canBeChangedBy($sam));
        $this->assertFalse($mine->canBeChangedBy($sam));
        $this->assertTrue($mine->canBeChangedBy($this->admin));
    }
}
