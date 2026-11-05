<?php

namespace Tests\Feature\Admin;

use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Enums\VersionTestRunStatus;
use App\Organization;
use App\Server;
use App\Support\VersionTesting\VersionFingerprint;
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

    private function activationRequest(User $admin, Application $app)
    {
        return $this->actingAs($admin)->post('/admin/service/shared-apps', [
            'app' => $app->id,
            'label' => 'My Shared App',
            'activate' => true,
            'web_server' => Server::factory()->create(['type' => 'web'])->id,
        ]);
    }

    public function test_cannot_activate_a_shared_app_whose_version_has_not_been_tested()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $this->activeVersion($app);

        $this->activationRequest($admin, $app)->assertSessionHasErrors('app');

        $this->assertNull(AppPlan::where('name', 'My Shared App')->first());
    }

    public function test_cannot_activate_a_shared_app_whose_version_failed_or_changed_since_testing()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $version = $this->activeVersion($app);

        $this->recordTestRun($version, VersionTestRunStatus::FAILED);
        $this->activationRequest($admin, $app)->assertSessionHasErrors('app');

        $version->testRuns()->delete();
        $this->recordTestRun($version, VersionTestRunStatus::PASSED);
        $version->admin_path = '/changed-after-testing';
        $version->save();
        $this->activationRequest($admin, $app)->assertSessionHasErrors('app');
    }

    public function test_the_version_gate_does_not_apply_when_the_shared_app_is_not_activated()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $this->activeVersion($app);

        $this->actingAs($admin)->post('/admin/service/shared-apps', [
            'app' => $app->id,
            'label' => 'My Independent App',
            'activate' => false,
        ])->assertSessionDoesntHaveErrors();
    }

    public function test_a_shared_app_can_be_activated_once_its_version_passed_or_was_bypassed()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $version = $this->activeVersion($app);

        foreach ([VersionTestRunStatus::PASSED, VersionTestRunStatus::BYPASSED] as $status) {
            $version->testRuns()->delete();
            $this->recordTestRun($version, $status);

            $this->activationRequest($admin, $app)->assertSessionDoesntHaveErrors('app');
        }
    }

    public function test_the_list_reports_whether_each_apps_version_can_be_activated()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $version = $this->activeVersion($app);

        $this->actingAs($admin)->get('/admin/service/shared-apps')->assertInertia(fn ($page) => $page
            ->where('available_apps.0.test.gate', 'untested')
            ->where('available_apps.0.test.can_activate', false)
            ->where('available_apps.0.test.version', $version->name));

        $this->recordTestRun($version, VersionTestRunStatus::BYPASSED);

        $this->actingAs($admin)->get('/admin/service/shared-apps')->assertInertia(fn ($page) => $page
            ->where('available_apps.0.test.can_activate', true));
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
