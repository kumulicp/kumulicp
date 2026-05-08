<?php

namespace Tests\Feature\Admin;

use App\Organization;
use App\Plan;
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
        $user->removeRole('control_panel_admin');

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
        $user->removeRole('control_panel_admin');

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
        $user->removeRole('control_panel_admin');

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
        $user->removeRole('control_panel_admin');

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
        $user->removeRole('control_panel_admin');

        $this->actingAs($user)
            ->post('/admin/service/plans/update_order', ['plans' => []])
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------------
    // Store tests
    // ---------------------------------------------------------------------------

    public function test_admin_can_create_a_plan()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/service/plans', [
                'name' => 'New Plan',
                'description' => 'A new plan description',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('plans', [
            'name' => 'New Plan',
            'description' => 'A new plan description',
        ]);
    }

    public function test_store_requires_name()
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->post('/admin/service/plans', [
                'description' => 'Missing name',
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
            ])
            ->assertSessionHasErrors('description');

        $this->assertDatabaseMissing('plans', ['name' => 'No Description Plan']);
    }

    public function test_new_plan_gets_next_display_order()
    {
        $user = $this->adminUser();

        $existing = Plan::factory()->create(['display_order' => 3]);

        $this->actingAs($user)
            ->post('/admin/service/plans', [
                'name' => 'Order Test Plan',
                'description' => 'Checking display order',
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
