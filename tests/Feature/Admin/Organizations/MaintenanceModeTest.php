<?php

namespace Tests\Feature\Admin\Organizations;

use App\Actions\Apps\ApplicationUpdate;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\Support\Facades\Action;
use App\Task;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * The maintenance mode button of an app's admin edit page: it turns the setting
 * on or off and rolls the app's release out again. What the release looks like in
 * maintenance mode is covered by MaintenanceModeTest in Feature/Applications.
 */
class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $organization;

    private AppInstance $appInstance;

    protected function setUp(): void
    {
        parent::setUp();

        $support = new TestSupports;
        $support->seed();
        $support->activateDemoApp();

        $this->admin = User::find(1);
        $this->organization = Organization::factory()->create(['type' => 'business']);

        $demoApp = Application::where('slug', 'demo_app')->first();
        $plan = AppPlan::factory()->create(['application_id' => $demoApp->id, 'hidden' => false]);

        $this->appInstance = new AppInstance;
        $this->appInstance->application_id = $demoApp->id;
        $this->appInstance->organization_id = $this->organization->id;
        $this->appInstance->version_id = 1;
        $this->appInstance->plan_id = $plan->id;
        $this->appInstance->name = 'demo-app-instance';
        $this->appInstance->label = 'Demo App Instance';
        $this->appInstance->status = 'active';
        $this->webServer('kubernetes');
    }

    private function webServer(string $interface): void
    {
        $server = Server::factory()->create(['interface' => $interface, 'type' => 'web']);
        $org_server = OrgServer::create(['organization_id' => $this->organization->id, 'server_id' => $server->id]);

        $this->appInstance->web_server_id = $org_server->id;
        $this->appInstance->save();
    }

    private function url(string $path = ''): string
    {
        return "/admin/organizations/{$this->organization->id}/apps/{$this->appInstance->id}$path";
    }

    public function test_the_edit_page_offers_maintenance_mode_for_an_app_on_a_kubernetes_server()
    {
        $this->actingAs($this->admin)
            ->get($this->url('/edit'))
            ->assertInertia(fn ($page) => $page
                ->where('maintenance.available', true)
                ->where('maintenance.enabled', false)
            );

        $this->appInstance->updateSetting('maintenance.enabled', true);

        $this->actingAs($this->admin)
            ->get($this->url('/edit'))
            ->assertInertia(fn ($page) => $page->where('maintenance.enabled', true));
    }

    public function test_the_app_page_and_the_apps_list_show_that_an_app_is_in_maintenance_mode()
    {
        $this->actingAs($this->admin)
            ->get($this->url())
            ->assertInertia(fn ($page) => $page->where('maintenance.enabled', false));

        $this->actingAs($this->admin)
            ->get("/admin/organizations/{$this->organization->id}/apps")
            ->assertInertia(fn ($page) => $page->where('apps.0.maintenance', false));

        $this->appInstance->updateSetting('maintenance.enabled', true);

        $this->actingAs($this->admin)
            ->get($this->url())
            ->assertInertia(fn ($page) => $page->where('maintenance.enabled', true));

        $this->actingAs($this->admin)
            ->get("/admin/organizations/{$this->organization->id}/apps")
            ->assertInertia(fn ($page) => $page->where('apps.0.maintenance', true));
    }

    public function test_the_edit_page_does_not_offer_it_on_a_server_that_does_not_deploy_charts()
    {
        $this->webServer('other');

        $this->actingAs($this->admin)
            ->get($this->url('/edit'))
            ->assertInertia(fn ($page) => $page->where('maintenance.available', false));
    }

    public function test_enabling_it_rolls_the_release_out_again()
    {
        Action::shouldReceive('execute')
            ->once()
            ->withArgs(fn ($action) => $action instanceof ApplicationUpdate)
            ->andReturn(new Task);

        $this->actingAs($this->admin)
            ->put($this->url('/maintenance'), ['enabled' => true])
            ->assertRedirect($this->url())
            ->assertSessionHas('success');

        $this->assertTrue($this->appInstance->fresh()->inMaintenance());
        // The roll out is what has the app updating
        $this->assertSame('updating', $this->appInstance->fresh()->status);
    }

    public function test_disabling_it_rolls_the_release_out_again()
    {
        $this->appInstance->updateSetting('maintenance.enabled', true);

        Action::shouldReceive('execute')->once()->andReturn(new Task);

        $this->actingAs($this->admin)
            ->put($this->url('/maintenance'), ['enabled' => false])
            ->assertRedirect($this->url())
            ->assertSessionHas('success');

        $this->assertFalse($this->appInstance->fresh()->inMaintenance());
    }

    public function test_a_roll_out_that_fails_to_start_leaves_the_app_as_it_was()
    {
        Action::shouldReceive('execute')->once()->andThrow(new \RuntimeException('queue is down'));

        $this->actingAs($this->admin)
            ->put($this->url('/maintenance'), ['enabled' => true])
            ->assertRedirect($this->url('/edit'))
            ->assertSessionHas('error');

        $this->assertFalse($this->appInstance->fresh()->inMaintenance());
        $this->assertSame('active', $this->appInstance->fresh()->status);
    }

    public function test_an_app_that_is_busy_is_refused()
    {
        $this->appInstance->status = 'updating';
        $this->appInstance->save();

        Action::shouldReceive('execute')->never();

        $this->actingAs($this->admin)
            ->put($this->url('/maintenance'), ['enabled' => true])
            ->assertRedirect($this->url('/edit'))
            ->assertSessionHas('error', __('admin.organizations.apps.denied.maintenance_not_active'));

        $this->assertFalse($this->appInstance->fresh()->inMaintenance());
    }

    public function test_an_app_on_a_server_that_does_not_deploy_charts_is_refused()
    {
        $this->webServer('other');

        Action::shouldReceive('execute')->never();

        $this->actingAs($this->admin)
            ->put($this->url('/maintenance'), ['enabled' => true])
            ->assertSessionHas('error', __('admin.organizations.apps.denied.maintenance_unsupported'));
    }

    public function test_it_is_refused_when_the_app_is_already_as_asked()
    {
        Action::shouldReceive('execute')->never();

        $this->actingAs($this->admin)
            ->put($this->url('/maintenance'), ['enabled' => false])
            ->assertSessionHas('error', __('admin.organizations.apps.denied.maintenance_already_off'));

        $this->appInstance->updateSetting('maintenance.enabled', true);

        $this->actingAs($this->admin)
            ->put($this->url('/maintenance'), ['enabled' => true])
            ->assertSessionHas('error', __('admin.organizations.apps.denied.maintenance_already_on'));
    }

    public function test_it_needs_to_be_told_what_to_do()
    {
        Action::shouldReceive('execute')->never();

        $this->actingAs($this->admin)
            ->put($this->url('/maintenance'), [])
            ->assertSessionHasErrors('enabled');
    }

    public function test_it_is_not_done_through_another_organization()
    {
        $other = Organization::factory()->create(['type' => 'business']);

        Action::shouldReceive('execute')->never();

        $this->actingAs($this->admin)
            ->put("/admin/organizations/{$other->id}/apps/{$this->appInstance->id}/maintenance", ['enabled' => true])
            ->assertNotFound();
    }
}
