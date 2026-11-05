<?php

namespace Tests\Feature\Admin;

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Enums\VersionTestRunStatus;
use App\Organization;
use App\Server;
use App\Support\VersionTesting\VersionFingerprint;
use App\Task;
use App\User;
use App\VersionTestRun;
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

    private function recordTestRun(AppVersion $version, VersionTestRunStatus $status): void
    {
        $run = new VersionTestRun;
        $run->application_id = $version->application_id;
        $run->app_version_id = $version->id;
        $run->status = $status;
        $run->fingerprint = (new VersionFingerprint)->for($version);
        $run->finished_at = now();
        $run->save();
    }

    private function activeVersion(Application $app): AppVersion
    {
        $version = $app->versions()->where('status', 'active')->firstOrFail();
        $version->testRuns()->delete();

        return $version;
    }

    private function addSharedApp(User $admin, Application $app, string $label = 'My Shared App'): AppInstance
    {
        $this->actingAs($admin)->post('/admin/service/shared-apps', [
            'app' => $app->id,
            'label' => $label,
        ])->assertSessionDoesntHaveErrors();

        return AppInstance::where('label', $label)->firstOrFail();
    }

    private function activationRequest(User $admin, AppInstance $shared, array $data = [])
    {
        return $this->actingAs($admin)->post("/admin/service/shared-apps/{$shared->id}/activate", $data + [
            'web_server' => Server::factory()->create(['type' => 'web'])->id,
        ]);
    }

    public function test_adding_a_shared_app_does_not_activate_it()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $webServer = Server::factory()->create(['type' => 'web']);

        $this->actingAs($admin)->post('/admin/service/shared-apps', [
            'app' => $app->id,
            'label' => 'My Shared App',
            // A request that tries to activate on the way in is ignored
            'activate' => true,
            'web_server' => $webServer->id,
        ])->assertSessionDoesntHaveErrors();

        $instance = AppInstance::where('label', 'My Shared App')->firstOrFail();
        $plan = AppPlan::where('name', 'My Shared App')->firstOrFail();

        $this->assertSame('deactivated', $instance->status);
        $this->assertTrue($plan->hidden);
        $this->assertNull($plan->web_server_id);
        $this->assertFalse($plan->isSharedAppActive());
        $this->assertSame(0, Task::where('action_slug', 'application_activate')->count());
    }

    public function test_the_activate_form_needs_a_web_server()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $version = $this->activeVersion($app);
        $this->recordTestRun($version, VersionTestRunStatus::PASSED);
        $shared = $this->addSharedApp($admin, $app);

        $this->actingAs($admin)->post("/admin/service/shared-apps/{$shared->id}/activate", [])
            ->assertSessionHasErrors('web_server');
    }

    public function test_cannot_activate_a_shared_app_whose_version_has_not_been_tested()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $this->activeVersion($app);
        $shared = $this->addSharedApp($admin, $app);

        $this->activationRequest($admin, $shared)->assertSessionHas('error');

        $this->assertSame('deactivated', $shared->refresh()->status);
        $this->assertNull($shared->plan->web_server_id);
        $this->assertSame(0, Task::where('action_slug', 'application_activate')->count());
    }

    public function test_cannot_activate_a_shared_app_whose_version_failed_or_changed_since_testing()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $version = $this->activeVersion($app);
        $shared = $this->addSharedApp($admin, $app);

        $this->recordTestRun($version, VersionTestRunStatus::FAILED);
        $this->activationRequest($admin, $shared)->assertSessionHas('error');

        $version->testRuns()->delete();
        $this->recordTestRun($version, VersionTestRunStatus::PASSED);
        $version->admin_path = '/changed-after-testing';
        $version->save();
        $this->activationRequest($admin, $shared)->assertSessionHas('error');

        $this->assertSame('deactivated', $shared->refresh()->status);
        $this->assertSame(0, Task::where('action_slug', 'application_activate')->count());
    }

    private function assertActivatedInPlace(VersionTestRunStatus $status): void
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $version = $this->activeVersion($app);
        $this->recordTestRun($version, $status);
        $shared = $this->addSharedApp($admin, $app);
        $webServer = Server::factory()->create(['type' => 'web']);

        $this->activationRequest($admin, $shared, ['web_server' => $webServer->id])
            ->assertSessionDoesntHaveErrors()
            ->assertSessionHas('success');

        $this->assertSame($webServer->id, $shared->plan->refresh()->web_server_id);
        $this->assertSame('activating', $shared->refresh()->status);

        $task = Task::where('action_slug', 'application_activate')->firstOrFail();
        $this->assertSame($shared->id, $task->app_instance_id);
        // The instance that was added is the one deployed, not a second one
        $this->assertSame(1, AppInstance::where('label', 'My Shared App')->count());
    }

    public function test_a_shared_app_is_activated_in_place_once_its_version_passed()
    {
        $this->assertActivatedInPlace(VersionTestRunStatus::PASSED);
    }

    public function test_a_shared_app_is_activated_in_place_once_its_version_tests_were_bypassed()
    {
        $this->assertActivatedInPlace(VersionTestRunStatus::BYPASSED);
    }

    public function test_a_shared_app_that_is_already_activating_cannot_be_activated_again()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $version = $this->activeVersion($app);
        $this->recordTestRun($version, VersionTestRunStatus::PASSED);
        $shared = $this->addSharedApp($admin, $app);
        $shared->status = 'activating';
        $shared->save();

        $this->activationRequest($admin, $shared)->assertSessionHas('error');

        $this->assertNull($shared->plan->refresh()->web_server_id);
        $this->assertSame(0, Task::where('action_slug', 'application_activate')->count());
    }

    public function test_the_page_reports_whether_the_shared_app_can_be_activated()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $version = $this->activeVersion($app);
        $shared = $this->addSharedApp($admin, $app);

        $this->actingAs($admin)->get("/admin/service/shared-apps/{$shared->id}")->assertInertia(fn ($page) => $page
            ->where('activation.pending', true)
            ->where('activation.gate', 'untested')
            ->where('activation.can_activate', false)
            ->where('activation.version', $version->name));

        $this->recordTestRun($version, VersionTestRunStatus::BYPASSED);

        $this->actingAs($admin)->get("/admin/service/shared-apps/{$shared->id}")->assertInertia(fn ($page) => $page
            ->where('activation.can_activate', true));
    }

    public function test_the_list_does_not_offer_activation_or_server_choices()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();

        $this->actingAs($admin)->get('/admin/service/shared-apps')->assertInertia(fn ($page) => $page
            ->missing('web_servers')
            ->missing('database_servers')
            ->missing('sso_servers'));
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

    public function test_enabling_shared_apps_creates_the_shared_organization_with_settings()
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->get('/admin/service/shared-apps/activate')->assertRedirect('/admin/service/shared-apps');

        $shared = Organization::where('type', 'shared')->firstOrFail();

        // MariaDB rejects the insert outright when settings is left out
        $this->assertSame([], $shared->settings);
    }

    public function test_the_list_only_offers_apps_that_can_be_shared()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $sharable = Application::where('slug', 'demo_app')->firstOrFail();
        // WordPress's profile doesn't declare it as shareable
        $wordpress = Application::factory()->create(['slug' => 'wordpress', 'name' => 'WordPress']);

        $this->actingAs($admin)->get('/admin/service/shared-apps')->assertInertia(fn ($page) => $page
            ->where('available_apps', fn ($apps) => collect($apps)->pluck('id')->contains($sharable->id)
                && collect($apps)->pluck('id')->doesntContain($wordpress->id)));
    }

    public function test_an_app_that_cannot_be_shared_cannot_be_added()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $wordpress = Application::factory()->create(['slug' => 'wordpress', 'name' => 'WordPress']);

        $this->actingAs($admin)->post('/admin/service/shared-apps', [
            'app' => $wordpress->id,
            'label' => 'My WordPress',
            'activate' => false,
        ])->assertSessionHasErrors('app');

        $this->assertNull(AppPlan::where('name', 'My WordPress')->first());
    }
}
