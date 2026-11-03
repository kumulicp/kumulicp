<?php

namespace Tests\Feature\Tickets;

use App\Services\Tickets\TicketAutomation;
use App\TicketCategory;
use App\TicketSlaPolicy;
use Inertia\Testing\AssertableInertia as Assert;

class TicketSettingsTest extends TicketsTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Standard',
            'active' => true,
            'is_default' => true,
            'targets' => ['normal' => ['first_response' => 1, 'resolution' => 8], 'urgent' => ['first_response' => 0.25, 'resolution' => 2]],
            'business_hours' => ['enabled' => false],
        ], $overrides);
    }

    public function test_only_staff_reach_the_settings(): void
    {
        $policy = $this->slaPolicy('Standard', $this->standardTargets(), null, true);

        $this->actingAs($this->alice)->get('/admin/support/settings')->assertForbidden();
        $this->actingAs($this->alice)->post('/admin/support/settings/policies', ['name' => 'X'])->assertForbidden();
        $this->actingAs($this->alice)->put('/admin/support/settings/policies/'.$policy->id, $this->payload())->assertForbidden();
        $this->actingAs($this->alice)->delete('/admin/support/settings/policies/'.$policy->id)->assertForbidden();
        $this->actingAs($this->alice)->put('/admin/support/settings/automation', [])->assertForbidden();

        $this->assertNotNull(TicketSlaPolicy::find($policy->id));
    }

    public function test_the_feature_flag_hides_the_settings(): void
    {
        config(['toggle.flags.tickets' => false]);

        $this->actingAs($this->admin)->get('/admin/support/settings')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/support/reports')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/support/canned')->assertNotFound();
    }

    public function test_the_first_policy_becomes_the_default_and_the_page_lists_it(): void
    {
        $this->actingAs($this->admin)->post('/admin/support/settings/policies', ['name' => 'Standard'])->assertRedirect();

        $policy = TicketSlaPolicy::firstOrFail();
        $this->assertTrue($policy->is_default);

        $this->actingAs($this->admin)->get('/admin/support/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tickets/TicketSettings')
                ->has('policies', 1)
                ->where('policies.0.name', 'Standard')
                ->has('automation')
                ->missing('automation.rr_last'));
    }

    public function test_hours_typed_by_an_administrator_are_stored_as_minutes(): void
    {
        $policy = $this->slaPolicy('Standard', [], null, true);

        $this->actingAs($this->admin)->put('/admin/support/settings/policies/'.$policy->id, $this->payload())->assertSessionHasNoErrors();

        $policy->refresh();
        $this->assertSame(60, $policy->target('normal', 'first_response'));
        $this->assertSame(480, $policy->target('normal', 'resolution'));
        $this->assertSame(15, $policy->target('urgent', 'first_response'));
        // A blank means no target
        $this->assertNull($policy->target('low', 'first_response'));
        $this->assertNull($policy->business_hours);
    }

    public function test_business_hours_are_validated_and_saved(): void
    {
        $policy = $this->slaPolicy('Standard', [], null, true);
        $hours = ['enabled' => true, 'timezone' => 'Europe/Madrid', 'days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00', 'holidays' => ['2026-12-25']];

        $this->actingAs($this->admin)->put('/admin/support/settings/policies/'.$policy->id, $this->payload(['business_hours' => $hours]))->assertSessionHasNoErrors();

        $this->assertSame(['timezone' => 'Europe/Madrid', 'days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00', 'holidays' => ['2026-12-25']], $policy->fresh()->business_hours);

        foreach ([
            'no timezone' => ['timezone' => ''],
            'unknown timezone' => ['timezone' => 'Mars/Olympus'],
            'no days' => ['days' => []],
            'day out of range' => ['days' => [8]],
            'ends before it starts' => ['start' => '17:00', 'end' => '09:00'],
            'bad time' => ['start' => '9am'],
            'bad holiday' => ['holidays' => ['christmas']],
        ] as $case => $override) {
            $this->actingAs($this->admin)
                ->put('/admin/support/settings/policies/'.$policy->id, $this->payload(['business_hours' => $override + $hours, 'name' => 'Changed']))
                ->assertSessionHasErrors();
            $this->assertSame('Standard', $policy->fresh()->name, "Saved despite: $case");
        }
    }

    public function test_only_one_policy_is_the_default(): void
    {
        $first = $this->slaPolicy('First', [], null, true);
        $second = $this->slaPolicy('Second', [], null, false);

        $this->actingAs($this->admin)->put('/admin/support/settings/policies/'.$second->id, $this->payload(['name' => 'Second', 'is_default' => true]))->assertSessionHasNoErrors();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_deleting_a_policy_frees_its_categories(): void
    {
        $policy = $this->slaPolicy('VIP', []);
        $category = new TicketCategory(['name' => 'VIP', 'slug' => 'vip']);
        $category->active = true;
        $category->sla_policy_id = $policy->id;
        $category->save();

        $this->actingAs($this->admin)->delete('/admin/support/settings/policies/'.$policy->id)->assertRedirect();

        $this->assertNull(TicketSlaPolicy::find($policy->id));
        $this->assertNull($category->fresh()->sla_policy_id);
    }

    public function test_a_category_can_use_a_policy_but_only_one_that_exists(): void
    {
        $policy = $this->slaPolicy('VIP', []);
        $category = new TicketCategory(['name' => 'VIP', 'slug' => 'vip']);
        $category->active = true;
        $category->save();

        $this->actingAs($this->admin)->put('/admin/support/settings/categories/'.$category->id, ['sla_policy_id' => $policy->id])->assertSessionHasNoErrors();
        $this->assertSame($policy->id, $category->fresh()->sla_policy_id);

        $this->actingAs($this->admin)->put('/admin/support/settings/categories/'.$category->id, ['sla_policy_id' => 9999])->assertSessionHasErrors('sla_policy_id');
        $this->assertSame($policy->id, $category->fresh()->sla_policy_id);

        $this->actingAs($this->admin)->put('/admin/support/settings/categories/'.$category->id, ['sla_policy_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($category->fresh()->sla_policy_id);
    }

    public function test_the_automation_rules_are_saved_and_validated(): void
    {
        $rules = ['auto_assign' => 'least_loaded', 'pool_ids' => [$this->admin->id], 'reminder_days' => 3, 'auto_resolve_days' => 7, 'auto_close_days' => 7, 'retention_days' => 365];

        $this->actingAs($this->admin)->put('/admin/support/settings/automation', $rules)->assertSessionHasNoErrors();

        $settings = app(TicketAutomation::class)->settings();
        $this->assertSame('least_loaded', $settings['auto_assign']);
        $this->assertSame([$this->admin->id], $settings['pool_ids']);
        $this->assertSame(365, $settings['retention_days']);

        foreach ([['auto_assign' => 'random'], ['reminder_days' => -1], ['auto_close_days' => 400], ['retention_days' => 4000], ['pool_ids' => 'everyone']] as $bad) {
            $this->actingAs($this->admin)->put('/admin/support/settings/automation', array_merge($rules, $bad))->assertSessionHasErrors(array_key_first($bad));
        }

        $this->assertSame(365, app(TicketAutomation::class)->settings()['retention_days']);
    }
}
