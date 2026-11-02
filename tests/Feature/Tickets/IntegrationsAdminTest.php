<?php

namespace Tests\Feature\Tickets;

use App\Jobs\Tickets\DeliverTicketIntegration;
use App\TicketDelivery;
use App\TicketExternalLink;
use App\TicketIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

class IntegrationsAdminTest extends TicketsTestCase
{
    public function test_only_staff_can_use_the_integration_pages(): void
    {
        $integration = $this->webhook();
        $base = '/admin/support/integrations';

        $this->actingAs($this->alice)->get($base)->assertForbidden();
        $this->actingAs($this->alice)->get($base.'/create?driver=github')->assertForbidden();
        $this->actingAs($this->alice)->post($base, ['driver' => 'github', 'name' => 'x'])->assertForbidden();
        $this->actingAs($this->alice)->get($base.'/'.$integration->id.'/edit')->assertForbidden();
        $this->actingAs($this->alice)->put($base.'/'.$integration->id, ['name' => 'x'])->assertForbidden();
        $this->actingAs($this->alice)->delete($base.'/'.$integration->id)->assertForbidden();
        $this->actingAs($this->alice)->postJson($base.'/'.$integration->id.'/test')->assertForbidden();
        $this->actingAs($this->alice)->postJson($base.'/'.$integration->id.'/secret/reveal')->assertForbidden();
        $this->actingAs($this->alice)->post($base.'/'.$integration->id.'/secret/rotate')->assertForbidden();
        $this->actingAs($this->alice)->get($base.'/'.$integration->id.'/deliveries')->assertForbidden();

        $this->assertNotNull(TicketIntegration::find($integration->id));
    }

    public function test_the_feature_flag_hides_the_pages(): void
    {
        config(['toggle.flags.tickets' => false]);

        $this->actingAs($this->admin)->get('/admin/support/integrations')->assertNotFound();
    }

    public function test_the_list_shows_installed_types_and_never_settings(): void
    {
        $this->webhook();
        $this->github();

        $this->actingAs($this->admin)->get('/admin/support/integrations')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tickets/IntegrationsList')
                ->has('integrations', 2)
                ->has('drivers', 3)
                ->where('drivers', fn ($drivers) => $drivers->pluck('key')->sort()->values()->all() === ['generic_webhook', 'github', 'gitlab'])
                ->missing('integrations.0.config'));
    }

    public function test_the_form_comes_from_the_drivers_own_schema(): void
    {
        $this->actingAs($this->admin)->get('/admin/support/integrations/create?driver=gitlab')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tickets/IntegrationEdit')
                ->where('driver.key', 'gitlab')
                ->where('driver.kind', 'tracker')
                ->where('driver.schema', fn ($schema) => $schema->pluck('key')->all() === ['token', 'project', 'base_url', 'labels'])
                ->where('integration', null));

        $this->actingAs($this->admin)->get('/admin/support/integrations/create?driver=nonsense')->assertNotFound();
    }

    public function test_a_webhook_is_created_with_a_generated_secret_shown_once(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/support/integrations', [
            'driver' => 'generic_webhook',
            'name' => 'Automation',
            'config' => ['url' => 'https://hooks.example.test/in'],
            'events' => ['ticket.created', 'message.created'],
        ]);

        $integration = TicketIntegration::firstOrFail();
        $response->assertRedirect('/admin/support/integrations/'.$integration->id.'/edit');
        $this->assertSame(40, strlen($integration->config['secret']));
        $this->assertSame(['ticket.created', 'message.created'], $integration->events);

        // The page it lands on shows the secret once...
        $this->actingAs($this->admin)->get('/admin/support/integrations/'.$integration->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page->where('revealed.secret', $integration->config['secret']));
        // ...and after that it is only available on request
        $this->actingAs($this->admin)->get('/admin/support/integrations/'.$integration->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page->where('revealed', null));
    }

    public function test_secrets_are_never_sent_to_the_browser_or_stored_in_the_clear(): void
    {
        $integration = $this->github();

        $this->actingAs($this->admin)->get('/admin/support/integrations/'.$integration->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page
                ->where('integration.config.token', '')
                ->where('integration.secrets_set.token', true)
                ->where('integration.config.repository', 'acme/platform'))
            ->assertDontSee('ghp_secret');

        $raw = \DB::table('ticket_integrations')->where('id', $integration->id)->value('config');
        $this->assertStringNotContainsString('ghp_secret', $raw);
        $this->assertStringNotContainsString('acme/platform', $raw);
    }

    public function test_a_tracker_is_created_with_its_options_and_filters(): void
    {
        $this->actingAs($this->admin)->post('/admin/support/integrations', [
            'driver' => 'github',
            'name' => 'Engineering',
            'config' => ['token' => 'ghp_new', 'repository' => 'acme/platform', 'labels' => 'support'],
            'options' => ['auto_create' => true, 'sync_state' => false, 'include_requester' => true],
            'filters' => ['types' => ['bug'], 'priorities' => ['high', 'urgent']],
        ])->assertRedirect();

        $integration = TicketIntegration::firstOrFail();
        $this->assertSame('ghp_new', $integration->config['token']);
        $this->assertTrue($integration->options['auto_create']);
        $this->assertFalse($integration->options['sync_state']);
        $this->assertTrue($integration->options['include_requester']);
        $this->assertSame(['bug'], $integration->filters['types']);
        $this->assertNull($integration->events);
    }

    public function test_settings_are_validated_the_way_the_driver_asks(): void
    {
        $post = fn (array $config, string $driver = 'github') => $this->actingAs($this->admin)->post('/admin/support/integrations', ['driver' => $driver, 'name' => 'x', 'config' => $config]);

        $post(['repository' => 'acme/platform'])->assertSessionHasErrors('config.token');
        $post(['token' => 't', 'repository' => 'not a repo'])->assertSessionHasErrors('config.repository');
        $post(['token' => 't', 'repository' => 'a/b', 'api_url' => 'http://github.example.test'])->assertSessionHasErrors('config.api_url');
        $post(['token' => 't', 'project' => 'just-one-word'], 'gitlab')->assertSessionHasErrors('config.project');
        $post(['token' => 't', 'project' => 'group/sub/project', 'base_url' => 'https://gitlab.example.test'], 'gitlab')->assertSessionHasNoErrors();
        $post(['token' => 't', 'project' => '12345'], 'gitlab')->assertSessionHasNoErrors();
        $post(['url' => 'http://hooks.example.test/in'], 'generic_webhook')->assertSessionHasErrors('config.url');

        // A webhook must listen for something
        $this->actingAs($this->admin)->post('/admin/support/integrations', ['driver' => 'generic_webhook', 'name' => 'x', 'config' => ['url' => 'https://hooks.example.test/in']])
            ->assertSessionHasErrors('events');
        $this->actingAs($this->admin)->post('/admin/support/integrations', ['driver' => 'generic_webhook', 'name' => 'x', 'config' => ['url' => 'https://hooks.example.test/in'], 'events' => ['made.up']])
            ->assertSessionHasErrors('events.0');
        $this->actingAs($this->admin)->post('/admin/support/integrations', ['driver' => 'github', 'name' => '', 'config' => ['token' => 't', 'repository' => 'a/b']])
            ->assertSessionHasErrors('name');
    }

    public function test_leaving_a_secret_blank_keeps_the_one_already_saved(): void
    {
        $integration = $this->github();

        $this->actingAs($this->admin)->put('/admin/support/integrations/'.$integration->id, [
            'name' => 'Renamed',
            'config' => ['token' => '', 'repository' => 'acme/other'],
        ])->assertRedirect();

        $integration->refresh();
        $this->assertSame('Renamed', $integration->name);
        $this->assertSame('ghp_secret', $integration->config['token']);
        $this->assertSame('acme/other', $integration->config['repository']);

        $this->actingAs($this->admin)->put('/admin/support/integrations/'.$integration->id, [
            'name' => 'Renamed', 'config' => ['token' => 'ghp_replaced', 'repository' => 'acme/other'],
        ]);
        $this->assertSame('ghp_replaced', $integration->fresh()->config['token']);
    }

    public function test_rotating_a_secret_keeps_the_old_one_working_for_a_week(): void
    {
        $integration = $this->webhook();

        $this->actingAs($this->admin)->post('/admin/support/integrations/'.$integration->id.'/secret/rotate')->assertRedirect();

        $config = $integration->fresh()->config;
        $this->assertNotSame('sekrit', $config['secret']);
        $this->assertSame('sekrit', $config['previous_secret']);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $config['previous_secret_until'], 5);
        // Editing the other settings later does not disturb the rotation
        $this->actingAs($this->admin)->put('/admin/support/integrations/'.$integration->id, ['name' => 'x', 'events' => ['ticket.created'], 'config' => ['url' => 'https://hooks.example.test/in']]);
        $this->assertSame('sekrit', $integration->fresh()->config['previous_secret']);
    }

    public function test_a_tracker_has_no_secret_to_rotate_or_reveal(): void
    {
        $integration = $this->github();

        $this->actingAs($this->admin)->post('/admin/support/integrations/'.$integration->id.'/secret/rotate')->assertNotFound();
        $this->actingAs($this->admin)->postJson('/admin/support/integrations/'.$integration->id.'/secret/reveal')->assertNotFound();
    }

    public function test_staff_can_ask_to_see_the_signing_secret_again(): void
    {
        $integration = $this->webhook();

        $this->actingAs($this->admin)->postJson('/admin/support/integrations/'.$integration->id.'/secret/reveal')
            ->assertOk()->assertJson(['secret' => 'sekrit']);
    }

    public function test_the_connection_can_be_tested_without_changing_anything(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['full_name' => 'acme/platform'], 200)]);
        $integration = $this->github();

        $this->actingAs($this->admin)->postJson('/admin/support/integrations/'.$integration->id.'/test')
            ->assertOk()->assertJson(['ok' => true, 'message' => 'Connected to acme/platform.']);

        Http::assertSentCount(1);
        $this->assertSame(0, TicketDelivery::count());
    }

    public function test_a_failed_test_says_why(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $integration = $this->github();

        $this->actingAs($this->admin)->postJson('/admin/support/integrations/'.$integration->id.'/test')
            ->assertOk()->assertJsonPath('ok', false)->assertJsonPath('message', 'The repository was not found, or the token cannot see it.');
    }

    public function test_switching_on_clears_the_failure_count(): void
    {
        $integration = $this->webhook([], ['enabled' => false, 'consecutive_failures' => 10, 'disabled_reason' => 'Switched off after 10 failures in a row.']);

        $this->actingAs($this->admin)->put('/admin/support/integrations/'.$integration->id.'/enabled', ['enabled' => true])->assertRedirect();

        $integration->refresh();
        $this->assertTrue($integration->enabled);
        $this->assertSame(0, $integration->consecutive_failures);
        $this->assertNull($integration->disabled_reason);

        $this->actingAs($this->admin)->put('/admin/support/integrations/'.$integration->id.'/enabled', ['enabled' => false]);
        $this->assertFalse($integration->fresh()->enabled);
    }

    public function test_deleting_removes_its_history_and_links_but_not_tickets(): void
    {
        $integration = $this->github();
        $ticket = $this->ticketFor($this->alice);
        $link = new TicketExternalLink;
        $link->ticket_id = $ticket->id;
        $link->integration_id = $integration->id;
        $link->external_id = '1';
        $link->save();

        $this->actingAs($this->admin)->delete('/admin/support/integrations/'.$integration->id)->assertRedirect('/admin/support/integrations');

        $this->assertNull(TicketIntegration::find($integration->id));
        $this->assertSame(0, TicketExternalLink::count());
        $this->assertSame(0, TicketDelivery::where('integration_id', $integration->id)->count());
        $this->assertNotNull($ticket->fresh());
    }

    public function test_the_delivery_log_pages_show_what_was_sent_and_how_it_went(): void
    {
        $integration = $this->webhook();
        $this->ticketFor($this->alice);
        $delivery = TicketDelivery::firstOrFail();

        $this->actingAs($this->admin)->get('/admin/support/integrations/'.$integration->id.'/deliveries')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tickets/IntegrationDeliveries')
                ->has('deliveries.data', 1)
                ->where('deliveries.data.0.event', 'ticket.created')
                ->where('deliveries.data.0.status', 'pending')
                ->where('deliveries.data.0.ticket.reference', config('tickets.prefix').'-'.$delivery->ticket->number)
                // The payload is fetched on demand, not with the list
                ->missing('deliveries.data.0.payload'));

        $this->actingAs($this->admin)->getJson('/admin/support/integrations/'.$integration->id.'/deliveries/'.$delivery->id)
            ->assertOk()->assertJsonPath('payload.event', 'ticket.created');

        $this->actingAs($this->admin)->get('/admin/support/integrations/'.$integration->id.'/deliveries?status=failed')
            ->assertInertia(fn (Assert $page) => $page->has('deliveries.data', 0));
    }

    public function test_a_delivery_cannot_be_read_through_another_integration(): void
    {
        $mine = $this->webhook();
        $other = $this->github();
        $this->ticketFor($this->alice);
        $delivery = TicketDelivery::where('integration_id', $mine->id)->firstOrFail();

        $this->actingAs($this->admin)->getJson('/admin/support/integrations/'.$other->id.'/deliveries/'.$delivery->id)->assertNotFound();
        $this->actingAs($this->admin)->post('/admin/support/integrations/'.$other->id.'/deliveries/'.$delivery->id.'/redeliver')->assertNotFound();
    }

    public function test_only_failed_deliveries_can_be_redelivered(): void
    {
        $integration = $this->webhook();
        $this->ticketFor($this->alice);
        $delivery = TicketDelivery::firstOrFail();
        Queue::fake();

        $this->actingAs($this->admin)->post('/admin/support/integrations/'.$integration->id.'/deliveries/'.$delivery->id.'/redeliver')->assertStatus(422);

        $delivery->forceFill(['status' => 'failed', 'attempts' => 8, 'error' => 'boom'])->save();
        $this->actingAs($this->admin)->post('/admin/support/integrations/'.$integration->id.'/deliveries/'.$delivery->id.'/redeliver')->assertRedirect();

        $delivery->refresh();
        $this->assertSame(['pending', 0, null], [$delivery->status, $delivery->attempts, $delivery->error]);
        Queue::assertPushed(DeliverTicketIntegration::class, fn ($job) => $job->deliveryId === $delivery->id);
    }
}
