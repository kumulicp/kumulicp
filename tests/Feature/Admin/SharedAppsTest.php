<?php

namespace Tests\Feature\Admin;

use App\Application;
use App\AppPlan;
use App\Organization;
use App\Server;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class SharedAppsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $support = new TestSupports;
        $support->seed();
        $support->activateDemoApp();

        config(['toggle.flags.shared-apps' => true]);

        return User::find(1);
    }

    private function sharedOrganization(): Organization
    {
        return Organization::factory()->create([
            'slug' => 'shared',
            'name' => 'Shared Apps',
            'type' => 'shared',
            'status' => 'active',
        ]);
    }

    public function test_requires_a_web_server_when_activating_a_shared_app()
    {
        $admin = $this->adminUser();
        $app = Application::where('slug', 'demo_app')->first();

        $response = $this->actingAs($admin)->post('/admin/service/shared-apps', [
            'app' => $app->id,
            'label' => 'My Shared App',
            'activate' => true,
        ]);

        $response->assertSessionHasErrors('web_server');
    }

    public function test_does_not_require_a_web_server_when_creating_a_shared_app_without_activating()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();

        $response = $this->actingAs($admin)->post('/admin/service/shared-apps', [
            'app' => $app->id,
            'label' => 'My Independent App',
            'activate' => false,
        ]);

        $response->assertSessionDoesntHaveErrors('web_server');
    }

    public function test_ignores_submitted_server_selections_when_the_shared_app_is_not_activated()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $webServer = Server::factory()->create(['type' => 'web']);

        $this->actingAs($admin)->post('/admin/service/shared-apps', [
            'app' => $app->id,
            'label' => 'My Independent App',
            'activate' => false,
            // A bypassed frontend could still submit this -- the backend
            // must ignore it when activate is false.
            'web_server' => $webServer->id,
        ])->assertSessionDoesntHaveErrors();

        $plan = AppPlan::where('name', 'My Independent App')->firstOrFail();

        $this->assertTrue($plan->hidden);
        $this->assertNull($plan->web_server_id);
        $this->assertFalse($plan->isSharedAppActive());
    }

    public function test_is_shared_app_active_is_true_only_for_a_hidden_plan_with_a_web_server_assigned()
    {
        $visiblePlan = AppPlan::factory()->create(['hidden' => false]);
        $hiddenWithoutServer = AppPlan::factory()->create(['hidden' => true]);
        $hiddenWithServer = AppPlan::factory()->create([
            'hidden' => true,
            'web_server_id' => Server::factory()->create(['type' => 'web'])->id,
        ]);

        $this->assertFalse($visiblePlan->isSharedAppActive());
        $this->assertFalse($hiddenWithoutServer->isSharedAppActive());
        $this->assertTrue($hiddenWithServer->isSharedAppActive());
    }
}
