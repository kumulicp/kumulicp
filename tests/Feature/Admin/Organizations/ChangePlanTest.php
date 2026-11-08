<?php

namespace Tests\Feature\Admin\Organizations;

use App\Organization;
use App\Plan;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class ChangePlanTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        (new TestSupports)->seed();

        return User::find(1);
    }

    private function organization(): Organization
    {
        return Organization::factory()->create(['type' => 'business', 'status' => 'active']);
    }

    public function test_org_page_lists_public_hidden_and_archived_plans_of_the_organizations_type()
    {
        $admin = $this->adminUser();
        $org = $this->organization();
        $public = Plan::factory()->create(['org_type' => 'business', 'status' => 'available', 'archive' => false]);
        $hidden = Plan::factory()->create(['org_type' => 'business', 'status' => 'hidden', 'archive' => false]);
        $archived = Plan::factory()->create(['org_type' => 'business', 'status' => 'available', 'archive' => true]);
        $other_type = Plan::factory()->create(['org_type' => 'nonprofit', 'status' => 'available']);

        $this->actingAs($admin)->get("/admin/organizations/{$org->id}")
            ->assertInertia(function ($page) use ($public, $hidden, $archived, $other_type) {
                $page->where('available_plans', function ($plans) use ($public, $hidden, $archived, $other_type) {
                    $plans = collect($plans)->keyBy('id');

                    return $plans[$public->id]['visibility'] === 'public'
                        && $plans[$hidden->id]['visibility'] === 'hidden'
                        && $plans[$archived->id]['visibility'] === 'archived'
                        && ! $plans->has($other_type->id);
                });
            });
    }

    public function test_admin_can_move_an_organization_to_a_hidden_archived_plan()
    {
        $admin = $this->adminUser();
        $org = $this->organization();
        $plan = Plan::factory()->create(['org_type' => 'business', 'status' => 'hidden', 'archive' => true]);

        $this->actingAs($admin)
            ->post("/admin/organizations/{$org->id}/change_plan", ['plan_id' => $plan->id])
            ->assertRedirect("/admin/organizations/{$org->id}")
            ->assertSessionHasNoErrors();

        $this->assertSame($plan->id, $org->fresh()->plan_id);
    }

    public function test_plan_of_another_organization_type_is_rejected()
    {
        $admin = $this->adminUser();
        $org = $this->organization();
        $original = $org->plan_id;
        $plan = Plan::factory()->create(['org_type' => 'nonprofit']);

        $this->actingAs($admin)
            ->post("/admin/organizations/{$org->id}/change_plan", ['plan_id' => $plan->id])
            ->assertSessionHasErrors('plan_id');

        $this->assertSame($original, $org->fresh()->plan_id);
    }

    public function test_non_admins_cannot_change_the_plan()
    {
        $this->adminUser();
        $org = $this->organization();
        $plan = Plan::factory()->create(['org_type' => 'business']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post("/admin/organizations/{$org->id}/change_plan", ['plan_id' => $plan->id])
            ->assertForbidden();
    }
}
