<?php

namespace Tests\Feature\Admin;

use App\Application;
use App\AppPlan;
use App\Organization;
use App\Plan;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Settings;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class PlansTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $support = new TestSupports;
        $support->seed();

        return User::find(1);
    }

    private function validUpdatePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Updated Plan',
            'description' => 'Updated description',
            'type' => 'package',
            'org_type' => 'none',
            'domain_enabled' => false,
            'email_enabled' => false,
        ], $overrides);
    }

    // ---------------------------------------------------------------------------
    // Auth tests
    // ---------------------------------------------------------------------------

    public function test_unauthenticated_user_is_redirected_from_plans_index()
    {
        (new TestSupports)->seed();

        $this->get('/admin/service/plans')
            ->assertRedirect('/login');
    }

    public function test_non_admin_user_cannot_access_plans_index()
    {
        $support = new TestSupports;
        $support->seed();

        // Remove admin role from demo user so they are a plain org member
        $user = User::find(1);
        AccountManager::users()->find('demo')->permissions()->removeControlPanelAdminAccess();

        $this->actingAs($user)
            ->get('/admin/service/plans')
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_store_a_plan()
    {
        (new TestSupports)->seed();

        $this->post('/admin/service/plans', ['name' => 'Test', 'description' => 'Desc'])
            ->assertRedirect('/login');
    }

    public function test_non_admin_user_cannot_store_a_plan()
    {
        $support = new TestSupports;
        $support->seed();

        $user = User::find(1);
        AccountManager::users()->find('demo')->permissions()->removeControlPanelAdminAccess();

        $this->actingAs($user)
            ->post('/admin/service/plans', ['name' => 'Test', 'description' => 'Desc'])
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_update_a_plan()
    {
        $support = new TestSupports;
        $support->seed();
        $plan = Plan::factory()->create();

        $this->post("/admin/service/plans/{$plan->id}", $this->validUpdatePayload())
            ->assertRedirect('/login');
    }

    public function test_non_admin_user_cannot_update_a_plan()
    {
        $support = new TestSupports;
        $support->seed();
        $plan = Plan::factory()->create();

        $user = User::find(1);
        AccountManager::users()->find('demo')->permissions()->removeControlPanelAdminAccess();

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->validUpdatePayload())
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_remove_a_plan()
    {
        $support = new TestSupports;
        $support->seed();
        $plan = Plan::factory()->create();

        $this->get("/admin/service/plans/{$plan->id}/remove")
            ->assertRedirect('/login');
    }

    public function test_non_admin_user_cannot_remove_a_plan()
    {
        $support = new TestSupports;
        $support->seed();
        $plan = Plan::factory()->create();

        $user = User::find(1);
        AccountManager::users()->find('demo')->permissions()->removeControlPanelAdminAccess();

        $this->actingAs($user)
            ->get("/admin/service/plans/{$plan->id}/remove")
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_update_plan_order()
    {
        (new TestSupports)->seed();

        $this->post('/admin/service/plans/update_order', ['plans' => []])
            ->assertRedirect('/login');
    }

    public function test_non_admin_user_cannot_update_plan_order()
    {
        $support = new TestSupports;
        $support->seed();

        $user = User::find(1);
        AccountManager::users()->find('demo')->permissions()->removeControlPanelAdminAccess();

        $this->actingAs($user)
            ->post('/admin/service/plans/update_order', ['plans' => []])
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------------
    // Store tests
    // ---------------------------------------------------------------------------

    public function test_admin_can_create_a_package_plan()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/service/plans', [
                'name' => 'New Package Plan',
                'description' => 'A package plan',
                'type' => 'package',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('plans', [
            'name' => 'New Package Plan',
            'type' => 'package',
        ]);
    }

    public function test_admin_can_create_an_app_plan()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/service/plans', [
                'name' => 'New App Plan',
                'description' => 'An app plan',
                'type' => 'app',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('plans', [
            'name' => 'New App Plan',
            'type' => 'app',
        ]);
    }

    public function test_store_requires_name()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/service/plans', [
                'description' => 'Missing name',
                'type' => 'package',
            ])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseMissing('plans', ['description' => 'Missing name']);
    }

    public function test_store_requires_description()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/service/plans', [
                'name' => 'No Description Plan',
                'type' => 'package',
            ])
            ->assertSessionHasErrors('description');

        $this->assertDatabaseMissing('plans', ['name' => 'No Description Plan']);
    }

    public function test_store_requires_type()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/service/plans', [
                'name' => 'No Type Plan',
                'description' => 'Missing type',
            ])
            ->assertSessionHasErrors('type');

        $this->assertDatabaseMissing('plans', ['name' => 'No Type Plan']);
    }

    public function test_store_rejects_invalid_type()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/service/plans', [
                'name' => 'Bad Type Plan',
                'description' => 'Invalid type value',
                'type' => 'invalid',
            ])
            ->assertSessionHasErrors('type');

        $this->assertDatabaseMissing('plans', ['name' => 'Bad Type Plan']);
    }

    public function test_new_plan_gets_next_display_order()
    {
        $user = $this->adminUser();

        $existing = Plan::factory()->create(['display_order' => 3]);

        $this->actingAs($user)
            ->post('/admin/service/plans', [
                'name' => 'Order Test Plan',
                'description' => 'Checking display order',
                'type' => 'package',
            ]);

        $created = Plan::where('name', 'Order Test Plan')->first();
        $this->assertEquals(4, $created->display_order);
    }

    // ---------------------------------------------------------------------------
    // Update tests
    // ---------------------------------------------------------------------------

    public function test_admin_can_update_a_plan()
    {
        $user = $this->adminUser();
        $plan = Plan::factory()->create(['name' => 'Old Name']);

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->validUpdatePayload([
                'name' => 'Updated Name',
                'description' => 'Updated description',
                'type' => 'app',
                'org_type' => 'nonprofit',
            ]))
            ->assertRedirect('/admin/service/plans');

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => 'Updated Name',
            'type' => 'app',
            'org_type' => 'nonprofit',
        ]);
    }

    public function test_update_requires_name()
    {
        $user = $this->adminUser();
        $plan = Plan::factory()->create(['name' => 'Original Name']);

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->validUpdatePayload(['name' => '']))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'name' => 'Original Name']);
    }

    public function test_update_requires_description()
    {
        $user = $this->adminUser();
        $plan = Plan::factory()->create();

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->validUpdatePayload(['description' => '']))
            ->assertSessionHasErrors('description');
    }

    public function test_update_requires_valid_type()
    {
        $user = $this->adminUser();
        $plan = Plan::factory()->create();

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->validUpdatePayload(['type' => 'invalid']))
            ->assertSessionHasErrors('type');
    }

    public function test_update_requires_valid_org_type()
    {
        $user = $this->adminUser();
        $plan = Plan::factory()->create();

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->validUpdatePayload(['org_type' => 'invalid']))
            ->assertSessionHasErrors('org_type');
    }

    public function test_update_sets_default_plan_and_clears_previous_default()
    {
        $user = $this->adminUser();

        $existing_default = Plan::factory()->create(['is_default' => true, 'org_type' => 'none']);
        $new_plan = Plan::factory()->create(['is_default' => false, 'org_type' => 'none']);

        $this->actingAs($user)
            ->post("/admin/service/plans/{$new_plan->id}", $this->validUpdatePayload([
                'default' => true,
                'org_type' => 'none',
            ]));

        $this->assertDatabaseHas('plans', ['id' => $new_plan->id, 'is_default' => true]);
        $this->assertDatabaseHas('plans', ['id' => $existing_default->id, 'is_default' => false]);
    }

    public function test_update_nulls_out_users_and_storage_settings_for_app_type_plans()
    {
        $user = $this->adminUser();
        $plan = Plan::factory()->create([
            'type' => 'package',
            'settings' => [
                'suborganizations' => ['enabled' => false],
                'base' => ['storage' => 1, 'minimal_label' => 'Constituent', 'prices' => ['USD' => ['amount' => 5, 'price_id' => 'base_stripe']]],
                'standard' => ['max' => 10, 'storage' => 1, 'prices' => ['USD' => ['amount' => 2, 'price_id' => 'std_stripe']]],
                'basic' => ['name' => 'Volunteer', 'amount' => 5, 'max' => 20, 'storage' => 0.5, 'prices' => ['USD' => ['amount' => 1, 'price_id' => 'basic_stripe']]],
                'storage' => ['max' => 50, 'amount' => 5, 'prices' => ['USD' => ['amount' => 1, 'price_id' => 'sto_stripe']]],
                'email' => ['max' => null, 'storage' => null, 'prices' => ['USD' => ['amount' => null, 'price_id' => null]]],
                'application' => ['max' => 5, 'prices' => ['USD' => ['amount' => 3, 'price_id' => 'app_stripe']]],
                'domains' => ['connect' => false, 'register' => false, 'transfer' => false],
            ],
        ]);

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->validUpdatePayload([
                'type' => 'app',
                'base' => ['minimal_label' => 'Constituent'],
                'standard' => ['max' => 10, 'storage' => 1],
                'basic' => ['name' => 'Volunteer', 'amount' => 5, 'max' => 20, 'storage' => 0.5],
                'storage' => ['max' => 50, 'amount' => 5],
                'prices' => [
                    'base' => ['USD' => ['amount' => 5, 'price_id' => 'base_stripe']],
                    'standard' => ['USD' => ['amount' => 2, 'price_id' => 'std_stripe']],
                    'basic' => ['USD' => ['amount' => 1, 'price_id' => 'basic_stripe']],
                    'storage' => ['USD' => ['amount' => 1, 'price_id' => 'sto_stripe']],
                    'application' => ['USD' => ['amount' => 3, 'price_id' => 'app_stripe']],
                ],
            ]))
            ->assertRedirect('/admin/service/plans');

        $plan->refresh();
        // Pricing fields must be nulled out for app type
        $this->assertNull($plan->setting('base.prices.USD.amount'));
        $this->assertNull($plan->setting('base.prices.USD.price_id'));
        $this->assertNull($plan->setting('standard.prices.USD.amount'));
        $this->assertNull($plan->setting('standard.max'));
        $this->assertNull($plan->setting('basic.name'));
        $this->assertNull($plan->setting('storage.prices.USD.amount'));
        $this->assertNull($plan->setting('application.prices.USD.amount'));
        // Non-pricing fields are preserved
        $this->assertEquals('Constituent', $plan->setting('base.minimal_label'));
    }

    // ---------------------------------------------------------------------------
    // Multi-currency tests
    // ---------------------------------------------------------------------------

    public function test_admin_can_update_a_plan_with_multiple_currencies()
    {
        $user = $this->adminUser();
        Settings::update('enabled_currencies', json_encode(['USD', 'CAD']));

        $plan = Plan::factory()->create(['type' => 'package', 'org_type' => 'none']);

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->validUpdatePayload([
                'prices' => [
                    'base' => [
                        'USD' => ['amount' => 10, 'price_id' => 'usd_base'],
                        'CAD' => ['amount' => 14, 'price_id' => 'cad_base'],
                    ],
                    'standard' => [
                        'USD' => ['amount' => 5, 'price_id' => 'usd_std'],
                        'CAD' => ['amount' => 7, 'price_id' => 'cad_std'],
                    ],
                    'basic' => [
                        'USD' => ['amount' => 3, 'price_id' => 'usd_basic'],
                        'CAD' => ['amount' => 4, 'price_id' => 'cad_basic'],
                    ],
                    'storage' => [
                        'USD' => ['amount' => 1, 'price_id' => 'usd_sto'],
                        'CAD' => ['amount' => 2, 'price_id' => 'cad_sto'],
                    ],
                ],
            ]))
            ->assertRedirect('/admin/service/plans');

        $plan->refresh();

        $this->assertEquals(10, $plan->setting('base.prices.USD.amount'));
        $this->assertEquals(14, $plan->setting('base.prices.CAD.amount'));
        $this->assertEquals('usd_base', $plan->setting('base.prices.USD.price_id'));
        $this->assertEquals('cad_base', $plan->setting('base.prices.CAD.price_id'));

        $this->assertEquals(5, $plan->setting('standard.prices.USD.amount'));
        $this->assertEquals(7, $plan->setting('standard.prices.CAD.amount'));

        $this->assertEquals(3, $plan->setting('basic.prices.USD.amount'));
        $this->assertEquals(4, $plan->setting('basic.prices.CAD.amount'));

        $this->assertEquals(1, $plan->setting('storage.prices.USD.amount'));
        $this->assertEquals(2, $plan->setting('storage.prices.CAD.amount'));
    }

    public function test_plan_edit_page_receives_all_enabled_currencies()
    {
        $user = $this->adminUser();
        Settings::update('enabled_currencies', json_encode(['USD', 'CAD', 'EUR']));

        $plan = Plan::factory()->create(['type' => 'package', 'org_type' => 'none']);

        $this->actingAs($user)
            ->get("/admin/service/plans/{$plan->id}")
            ->assertInertia(fn ($page) => $page->where('enabled_currencies', ['USD', 'CAD', 'EUR']));
    }

    public function test_update_rejects_non_numeric_currency_amount()
    {
        $user = $this->adminUser();
        Settings::update('enabled_currencies', json_encode(['USD', 'CAD']));

        $plan = Plan::factory()->create(['type' => 'package', 'org_type' => 'none']);

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->validUpdatePayload([
                'prices' => [
                    'base' => [
                        'USD' => ['amount' => 10, 'price_id' => 'usd_base'],
                        'CAD' => ['amount' => 'not-a-number', 'price_id' => 'cad_base'],
                    ],
                ],
            ]))
            ->assertSessionHasErrors('prices.base.CAD.amount');
    }

    public function test_update_normalizes_single_app_plan_value_to_array()
    {
        $user = $this->adminUser();
        $plan = Plan::factory()->create(['type' => 'app']);

        $app = Application::factory()->create(['slug' => 'test_app_norm', 'name' => 'Test App Norm', 'enabled' => true]);
        $appPlan = AppPlan::factory()->create(['application_id' => $app->id]);

        $this->actingAs($user)
            ->post("/admin/service/plans/{$plan->id}", $this->validUpdatePayload([
                'type' => 'app',
                'app_plans' => [
                    $app->slug => ['max' => 1, 'plans' => $appPlan->id],
                ],
            ]))
            ->assertRedirect('/admin/service/plans');

        $plan->refresh();
        $saved = $plan->app_plans[$app->slug]['plans'] ?? null;
        $this->assertIsArray($saved, 'plans should be stored as an array');
        $this->assertEquals([$appPlan->id], $saved);
    }

    // ---------------------------------------------------------------------------
    // Remove tests
    // ---------------------------------------------------------------------------

    public function test_admin_can_remove_a_plan_with_no_subscribers()
    {
        $user = $this->adminUser();
        $plan = Plan::factory()->create();

        $this->actingAs($user)
            ->get("/admin/service/plans/{$plan->id}/remove")
            ->assertRedirect('/admin/service/plans');

        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
    }

    public function test_cannot_remove_a_plan_with_active_subscribers()
    {
        $user = $this->adminUser();
        $plan = Plan::factory()->create();

        // Attach an organization as a subscriber
        $org = Organization::find(1);
        $org->plan_id = $plan->id;
        $org->save();

        $this->actingAs($user)
            ->get("/admin/service/plans/{$plan->id}/remove")
            ->assertRedirect('/admin/service/plans')
            ->assertSessionHas('error');

        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
    }

    // ---------------------------------------------------------------------------
    // Update order tests
    // ---------------------------------------------------------------------------

    public function test_admin_can_update_plan_order()
    {
        $user = $this->adminUser();

        $plan_a = Plan::factory()->create(['display_order' => 1]);
        $plan_b = Plan::factory()->create(['display_order' => 2]);
        $plan_c = Plan::factory()->create(['display_order' => 3]);

        // Reverse the order
        $this->actingAs($user)
            ->post('/admin/service/plans/update_order', [
                'plans' => [
                    ['id' => $plan_c->id],
                    ['id' => $plan_b->id],
                    ['id' => $plan_a->id],
                ],
            ])
            ->assertRedirect('/admin/service/plans');

        $this->assertDatabaseHas('plans', ['id' => $plan_c->id, 'display_order' => 1]);
        $this->assertDatabaseHas('plans', ['id' => $plan_b->id, 'display_order' => 2]);
        $this->assertDatabaseHas('plans', ['id' => $plan_a->id, 'display_order' => 3]);
    }

    public function test_update_order_accepts_empty_plans_array()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/service/plans/update_order', ['plans' => []])
            ->assertRedirect('/admin/service/plans');
    }
}
