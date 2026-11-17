<?php

namespace Tests\Feature\Tickets;

use App\AppInstance;
use App\Enums\TicketStatus;
use App\Organization;
use App\TicketEvent;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Tickets are mostly about one of the organization's apps: it is linked, can be
 * changed afterwards, and both lists can be filtered by it.
 */
class TicketAppTest extends TicketsTestCase
{
    private function app(Organization $organization, string $label): AppInstance
    {
        // Built by hand: AppInstance is fully guarded and needs no real application for these tests
        $app = new AppInstance;
        $app->application_id = 1;
        $app->version_id = 1;
        $app->organization_id = $organization->id;
        $app->name = strtolower($label).'-'.$organization->id;
        $app->label = $label;
        $app->api_password = '';
        $app->status = 'active';
        $app->save();

        return $app;
    }

    public function test_a_ticket_links_to_its_app_on_both_sides(): void
    {
        $wordpress = $this->app($this->orgA, 'Blog');
        $ticket = $this->ticketFor($this->alice, ['app_instance_id' => $wordpress->id]);

        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number)
            ->assertInertia(fn (Assert $page) => $page
                ->where('ticket.app_instance', ['id' => $wordpress->id, 'label' => 'Blog', 'url' => '/apps/'.$wordpress->id])
                ->where('apps', [['value' => $wordpress->id, 'text' => 'Blog']])
                ->where('can.edit_app', true));

        $this->actingAs($this->admin)->get('/admin/support/tickets/'.$ticket->number)
            ->assertInertia(fn (Assert $page) => $page
                ->where('ticket.app_instance.url', '/admin/organizations/'.$this->orgA->id.'/apps/'.$wordpress->id)
                ->where('app_options', [['value' => $wordpress->id, 'text' => 'Blog']])
                ->where('context.apps.0.tickets_url', '/admin/support/tickets?assigned=all&status=all&app='.$wordpress->id));
    }

    public function test_the_new_ticket_form_can_be_opened_for_an_app(): void
    {
        $blog = $this->app($this->orgA, 'Blog');
        $foreign = $this->app($this->orgB, 'Their app');

        $this->actingAs($this->alice)->get('/support/tickets/create?app='.$blog->id)
            ->assertInertia(fn (Assert $page) => $page->where('selected_app', $blog->id));

        // Somebody else's app (or rubbish) is not preselected
        $this->actingAs($this->alice)->get('/support/tickets/create?app='.$foreign->id)
            ->assertInertia(fn (Assert $page) => $page->where('selected_app', null));
        $this->actingAs($this->alice)->get('/support/tickets/create?app=abc')
            ->assertInertia(fn (Assert $page) => $page->where('selected_app', null));
    }

    public function test_the_customer_list_filters_by_app(): void
    {
        $blog = $this->app($this->orgA, 'Blog');
        $shop = $this->app($this->orgA, 'Shop');
        $foreign = $this->app($this->orgB, 'Their app');
        $this->ticketFor($this->alice, ['subject' => 'Blog down', 'app_instance_id' => $blog->id]);
        $this->ticketFor($this->alice, ['subject' => 'Shop slow', 'app_instance_id' => $shop->id]);
        $this->ticketFor($this->alice, ['subject' => 'General question']);
        $this->ticketFor($this->carol, ['subject' => 'Other org', 'app_instance_id' => $foreign->id]);

        $subjects = fn (string $query) => $this->actingAs($this->alice)->get('/support/tickets?assigned=all&status=all'.$query)
            ->viewData('page')['props']['tickets']['data'];

        $this->assertSame(['Blog down'], array_column($subjects('&app='.$blog->id), 'subject'));
        $this->assertSame(['General question'], array_column($subjects('&app=none'), 'subject'));
        $this->assertCount(3, $subjects(''));
        // Another organization's app (or nonsense) is not a filter
        $this->assertCount(3, $subjects('&app='.$foreign->id));
        $this->assertCount(3, $subjects('&app=abc'));
        // Typing an app's name finds its tickets
        $this->assertSame(['Shop slow'], array_column($subjects('&q=Shop'), 'subject'));

        $this->actingAs($this->alice)->get('/support/tickets?assigned=all&status=all&app='.$blog->id)
            ->assertInertia(fn (Assert $page) => $page->where('filters.app', (string) $blog->id)->has('apps', 2));
    }

    public function test_the_staff_inbox_filters_by_app_across_organizations(): void
    {
        $blog = $this->app($this->orgA, 'Blog');
        $mine = $this->app($this->orgB, 'Blog');
        $unused = $this->app($this->orgA, 'Never ticketed');
        $this->ticketFor($this->alice, ['subject' => 'Acme blog', 'app_instance_id' => $blog->id]);
        $this->ticketFor($this->carol, ['subject' => 'Globex blog', 'app_instance_id' => $mine->id]);
        $this->ticketFor($this->bob, ['subject' => 'No app']);

        $get = fn (string $query) => $this->actingAs($this->admin)->get('/admin/support/tickets?assigned=all&status=all'.$query);

        $this->assertSame(['Acme blog'], array_column($get('&app='.$blog->id)->viewData('page')['props']['tickets']['data'], 'subject'));
        $this->assertSame(['No app'], array_column($get('&app=none')->viewData('page')['props']['tickets']['data'], 'subject'));
        $this->assertCount(3, $get('')->viewData('page')['props']['tickets']['data']);

        // Only apps with tickets are offered, told apart by organization; one being filtered on stays selectable
        $get('')->assertInertia(fn (Assert $page) => $page->where('apps', fn ($apps) => collect($apps)->pluck('text')->sort()->values()->all() === ['Blog - Acme', 'Blog - Globex']));
        $get('&app='.$unused->id)->assertInertia(fn (Assert $page) => $page->has('apps', 3));
    }

    public function test_a_customer_can_change_the_app_while_the_ticket_is_open(): void
    {
        $blog = $this->app($this->orgA, 'Blog');
        $shop = $this->app($this->orgA, 'Shop');
        $ticket = $this->ticketFor($this->alice, ['app_instance_id' => $blog->id]);

        $this->actingAs($this->bob)->put('/support/tickets/'.$ticket->number, ['app_instance_id' => $shop->id])->assertRedirect();

        $this->assertSame($shop->id, $ticket->fresh()->app_instance_id);
        $event = TicketEvent::where('ticket_id', $ticket->id)->where('type', 'app_changed')->firstOrFail();
        $this->assertSame(['from' => $blog->id, 'to' => $shop->id, 'from_label' => 'Blog', 'to_label' => 'Shop'], $event->context);
        $this->assertFalse($event->internal);

        // The customer sees it in the activity
        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number)
            ->assertInertia(fn (Assert $page) => $page->where('timeline', fn ($timeline) => collect($timeline)->pluck('type')->contains('app_changed')));

        // Clearing it works too; the same value again records nothing new
        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number, ['app_instance_id' => null])->assertRedirect();
        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number, ['app_instance_id' => null])->assertRedirect();
        $this->assertNull($ticket->fresh()->app_instance_id);
        $this->assertSame(2, TicketEvent::where('ticket_id', $ticket->id)->where('type', 'app_changed')->count());
    }

    public function test_only_the_organizations_own_apps_can_be_chosen(): void
    {
        $blog = $this->app($this->orgA, 'Blog');
        $foreign = $this->app($this->orgB, 'Their app');
        $ticket = $this->ticketFor($this->alice, ['app_instance_id' => $blog->id]);

        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number, ['app_instance_id' => $foreign->id])
            ->assertSessionHasErrors('app_instance_id');
        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number, ['app_instance_id' => $foreign->id])
            ->assertSessionHasErrors('app_instance_id');
        $this->actingAs($this->alice)->post('/support/tickets', ['subject' => 'x', 'body' => '<p>x</p>', 'app_instance_id' => $foreign->id])
            ->assertSessionHasErrors('app_instance_id');

        $this->assertSame($blog->id, $ticket->fresh()->app_instance_id);
    }

    public function test_other_organizations_cannot_change_it(): void
    {
        $blog = $this->app($this->orgA, 'Blog');
        $ticket = $this->ticketFor($this->alice, ['app_instance_id' => $blog->id]);

        $this->actingAs($this->carol)->put('/support/tickets/'.$ticket->number, ['app_instance_id' => null])->assertNotFound();

        $this->assertSame($blog->id, $ticket->fresh()->app_instance_id);
    }

    public function test_a_finished_ticket_keeps_its_app_for_customers_but_not_for_staff(): void
    {
        $blog = $this->app($this->orgA, 'Blog');
        $shop = $this->app($this->orgA, 'Shop');
        $ticket = $this->ticketFor($this->alice, ['app_instance_id' => $blog->id]);
        $ticket->forceFill(['status' => TicketStatus::CLOSED])->save();

        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number)
            ->assertInertia(fn (Assert $page) => $page->where('can.edit_app', false));
        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number, ['app_instance_id' => $shop->id])->assertForbidden();
        $this->assertSame($blog->id, $ticket->fresh()->app_instance_id);

        // Support can still correct it
        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number, ['app_instance_id' => $shop->id])->assertSessionHasNoErrors();
        $this->assertSame($shop->id, $ticket->fresh()->app_instance_id);
    }
}
