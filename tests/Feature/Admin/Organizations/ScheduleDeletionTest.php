<?php

namespace Tests\Feature\Admin\Organizations;

use App\Actions\Organizations\DeactivateOrganization;
use App\Actions\Organizations\DeleteOrganization;
use App\Console\Calls\ScheduledOrganizationDeletionCheck;
use App\Organization;
use App\Plan;
use App\Support\Facades\Action;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestSupports;
use Tests\TestCase;

class ScheduleDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        (new TestSupports)->seed();

        return User::find(1);
    }

    private function organization(array $attributes = []): Organization
    {
        $plan = Plan::factory()->create(['org_type' => 'business']);

        return Organization::factory()->create(array_merge(['type' => 'business', 'status' => 'active', 'plan_id' => $plan->id], $attributes));
    }

    public function test_org_page_exposes_the_scheduled_deletion_date()
    {
        $admin = $this->adminUser();
        $org = $this->organization(['deletion_scheduled_at' => now()->addDays(30)]);

        $this->actingAs($admin)->get("/admin/organizations/{$org->id}")
            ->assertInertia(fn ($page) => $page->where('organization.deletion_scheduled_at', $org->fresh()->deletion_scheduled_at->toIso8601String()));
    }

    public function test_admin_can_schedule_deletion_and_deactivate_the_organization()
    {
        $admin = $this->adminUser();
        $org = $this->organization();
        $date = now()->addDays(30);

        Action::shouldReceive('execute')->once()->withArgs(fn ($action) => $action instanceof DeactivateOrganization);

        $this->actingAs($admin)
            ->post("/admin/organizations/{$org->id}/schedule_deletion", ['date' => $date->toIso8601String()])
            ->assertRedirect("/admin/organizations/{$org->id}");

        $this->assertEquals($date->timestamp, $org->fresh()->deletion_scheduled_at->timestamp);
    }

    public function test_deleting_now_is_set_by_the_server_two_minutes_ahead()
    {
        $admin = $this->adminUser();
        $org = $this->organization(['status' => 'deactivated']);
        $this->travelTo(now()->setTime(11, 35, 0));

        $this->actingAs($admin)
            ->post("/admin/organizations/{$org->id}/schedule_deletion", ['now' => true, 'date' => now()->subHours(3)->toIso8601String()])
            ->assertRedirect()
            ->assertSessionHas('success', __('admin.organizations.deletion_scheduled_in_minutes', ['minutes' => 2]));

        $this->assertEquals('11:37:00', $org->fresh()->deletion_scheduled_at->format('H:i:s'));
        // The stored wall-clock time is the app timezone's, not UTC
        $this->assertEquals('11:37:00', substr(DB::table('organizations')->where('id', $org->id)->value('deletion_scheduled_at'), 11));
    }

    public function test_an_already_deactivated_organization_is_not_deactivated_again()
    {
        $admin = $this->adminUser();
        $org = $this->organization(['status' => 'deactivated']);

        Action::shouldReceive('execute')->never();

        $this->actingAs($admin)
            ->post("/admin/organizations/{$org->id}/schedule_deletion", ['date' => now()->addDays(30)->toIso8601String()])
            ->assertRedirect();

        $this->assertNotNull($org->fresh()->deletion_scheduled_at);
    }

    public function test_deletion_date_is_required_and_cannot_be_in_the_past()
    {
        $admin = $this->adminUser();
        $org = $this->organization();

        $this->actingAs($admin)
            ->post("/admin/organizations/{$org->id}/schedule_deletion", [])
            ->assertSessionHasErrors('date');

        $this->actingAs($admin)
            ->post("/admin/organizations/{$org->id}/schedule_deletion", ['date' => now()->subDays(10)->toIso8601String()])
            ->assertSessionHasErrors('date');

        $this->assertNull($org->fresh()->deletion_scheduled_at);
    }

    public function test_admin_can_cancel_a_scheduled_deletion()
    {
        $admin = $this->adminUser();
        $org = $this->organization(['deletion_scheduled_at' => now()->addDays(30)]);

        $this->actingAs($admin)
            ->delete("/admin/organizations/{$org->id}/schedule_deletion")
            ->assertRedirect("/admin/organizations/{$org->id}");

        $this->assertNull($org->fresh()->deletion_scheduled_at);
    }

    public function test_notifying_requires_a_scheduled_deletion()
    {
        $admin = $this->adminUser();
        $org = $this->organization();

        $this->actingAs($admin)
            ->post("/admin/organizations/{$org->id}/notify_deletion")
            ->assertSessionHasErrors('date');
    }

    public function test_the_sweep_deletes_only_organizations_that_are_due()
    {
        $due = $this->organization(['status' => 'deactivated', 'deletion_scheduled_at' => now()->subMinute()]);
        $later = $this->organization(['status' => 'deactivated', 'deletion_scheduled_at' => now()->addDays(5)]);
        $unscheduled = $this->organization(['status' => 'deactivated']);

        Action::shouldReceive('execute')->once()->withArgs(fn ($action) => $action instanceof DeleteOrganization && $action->organization->is($due));

        (new ScheduledOrganizationDeletionCheck)();
    }
}
