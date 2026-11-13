<?php

namespace Tests\Feature\Admin;

use App\Actions\Tests\ProvisionVersionTestStep;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Enums\ApplicationSource;
use App\Enums\TaskStatus;
use App\Enums\VersionTestRunStatus;
use App\Jobs\Applications\AddLdapGroups;
use App\Organization;
use App\OrgSubdomain;
use App\Server;
use App\Support\Facades\Action;
use App\Support\VersionTesting\VersionFingerprint;
use App\Task;
use App\User;
use App\VersionTestRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
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

    private function recordTestRun(AppVersion $version, VersionTestRunStatus $status, ?AppPlan $plan = null): VersionTestRun
    {
        $run = new VersionTestRun;
        $run->application_id = $version->application_id;
        $run->app_version_id = $version->id;
        $run->app_plan_id = $plan?->id;
        $run->status = $status;
        $run->fingerprint = $plan ? (new VersionFingerprint)->forPlan($version, $plan) : (new VersionFingerprint)->for($version);
        $run->finished_at = now();
        $run->save();

        return $run;
    }

    private function activeVersion(Application $app): AppVersion
    {
        $version = $app->versions()->where('status', 'active')->firstOrFail();
        $version->testRuns()->delete();

        return $version;
    }

    private function addSharedApp(User $admin, Application $app, string $label = 'My Shared App'): AppInstance
    {
        // Recorded, not run: the job writes to LDAP, which these tests don't need
        Bus::fake([AddLdapGroups::class]);

        $this->actingAs($admin)->post('/admin/service/shared-apps', [
            'app' => $app->id,
            'label' => $label,
        ])->assertSessionDoesntHaveErrors();

        return AppInstance::where('label', $label)->firstOrFail();
    }

    // The servers are edited on the plan page, which the plan tests cover
    private function giveServers(AppInstance $shared, ?Server $web = null): AppPlan
    {
        $plan = $shared->plan;
        $plan->web_server_id = ($web ?? Server::factory()->create(['type' => 'web']))->id;
        $plan->save();

        return $plan;
    }

    private function activationRequest(User $admin, AppInstance $shared)
    {
        return $this->actingAs($admin)->post("/admin/service/shared-apps/{$shared->id}/activate");
    }

    /**
     * A shared app that is added, has a web server saved and is ready to be tested.
     *
     * @return array{0: User, 1: AppInstance, 2: AppVersion, 3: AppPlan}
     */
    private function readyToTest(): array
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $version = $this->activeVersion($app);
        $shared = $this->addSharedApp($admin, $app);
        $this->giveServers($shared);

        return [$admin, $shared->refresh(), $version, $shared->plan->refresh()];
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

        $this->assertSame(ApplicationSource::Profile, $app->refresh()->source);
        $this->assertSame('deactivated', $instance->status);
        // Nothing is filled in until it is activated
        $this->assertNull($instance->settings);
        $this->assertFalse($instance->isManagedExternally());
        $this->assertTrue($plan->hidden);
        $this->assertNull($plan->web_server_id);
        $this->assertSame(0, Task::where('action_slug', 'application_activate')->count());
    }

    public function test_tests_and_activation_wait_for_a_web_server()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $shared = $this->addSharedApp($admin, Application::where('slug', 'demo_app')->first());

        $page = "/admin/service/shared-apps/{$shared->id}";
        $this->actingAs($admin)->post("/admin/service/shared-apps/{$shared->id}/tests")->assertRedirect($page)->assertSessionHas('error');
        $this->actingAs($admin)->post("/admin/service/shared-apps/{$shared->id}/tests/bypass", ['reason' => 'Already trusted', 'acknowledge' => true])->assertRedirect($page)->assertSessionHas('error');
        $this->activationRequest($admin, $shared)->assertRedirect($page)->assertSessionHas('error');

        $this->assertSame(0, VersionTestRun::count());
    }

    public function test_the_tests_run_on_the_shared_apps_own_plan_only()
    {
        [$admin, $shared, $version, $plan] = $this->readyToTest();

        // Setting the test org up is a task of its own, covered elsewhere
        Action::shouldReceive('execute')->once()->with(Mockery::type(ProvisionVersionTestStep::class))->andReturn(Task::forceCreate([
            'organization_id' => 1,
            'action_group' => 'system',
            'action_slug' => 'fake',
            'status' => TaskStatus::IN_PROGRESS,
        ]));

        $this->actingAs($admin)->post("/admin/service/shared-apps/{$shared->id}/tests")
            ->assertSessionDoesntHaveErrors()
            ->assertSessionHas('success');

        $run = VersionTestRun::firstOrFail();
        $this->assertSame($plan->id, $run->app_plan_id);
        $this->assertSame($version->id, $run->app_version_id);
        $this->assertSame(1, $run->steps()->count());
        $this->assertSame($plan->id, $run->steps()->first()->app_plan_id);
        $this->assertSame('fresh_install', $run->steps()->first()->type->value);
        // It is a run of the plan, not of the version
        $this->assertSame('untested', $version->testGate());
        $this->assertSame('running', $version->testGate($plan));
    }

    public function test_bypassing_the_tests_is_recorded_for_the_plan_and_not_the_version()
    {
        [$admin, $shared, $version, $plan] = $this->readyToTest();
        $url = "/admin/service/shared-apps/{$shared->id}/tests/bypass";

        $this->actingAs($admin)->post($url, ['reason' => 'x', 'acknowledge' => false])->assertSessionHasErrors(['reason', 'acknowledge']);
        $this->assertSame(0, VersionTestRun::count());

        $this->actingAs($admin)->post($url, ['reason' => 'Already running elsewhere', 'acknowledge' => true])
            ->assertSessionDoesntHaveErrors()
            ->assertSessionHas('success');

        $run = VersionTestRun::firstOrFail();
        $this->assertSame($plan->id, $run->app_plan_id);
        $this->assertSame($admin->id, $run->bypassed_by_id);
        $this->assertSame('bypassed', $version->testGate($plan));
        $this->assertSame('untested', $version->testGate());
    }

    public function test_cannot_activate_a_shared_app_that_has_not_been_tested()
    {
        [$admin, $shared] = $this->readyToTest();

        $this->activationRequest($admin, $shared)->assertSessionHas('error');

        $this->assertSame('deactivated', $shared->refresh()->status);
        $this->assertSame(0, Task::where('action_slug', 'application_activate')->count());
    }

    public function test_the_versions_own_test_result_does_not_count_for_the_shared_app()
    {
        [$admin, $shared, $version] = $this->readyToTest();

        // The version passed everywhere customers can pick, and was even bypassed once
        $this->recordTestRun($version, VersionTestRunStatus::PASSED);
        $this->recordTestRun($version, VersionTestRunStatus::BYPASSED);
        $this->assertTrue($version->canBeEnabled());

        $this->activationRequest($admin, $shared)->assertSessionHas('error');

        $this->assertSame(0, Task::where('action_slug', 'application_activate')->count());
    }

    public function test_cannot_activate_when_the_plan_failed_or_changed_since_testing()
    {
        [$admin, $shared, $version, $plan] = $this->readyToTest();

        $this->recordTestRun($version, VersionTestRunStatus::FAILED, $plan);
        $this->activationRequest($admin, $shared)->assertSessionHas('error');

        // Passing, then changing its servers
        $version->planTestRuns($plan)->delete();
        $this->recordTestRun($version, VersionTestRunStatus::PASSED, $plan);
        $this->assertSame('passed', $version->testGate($plan));
        $this->giveServers($shared);
        $this->assertSame('stale', $version->testGate($plan->refresh()));
        $this->activationRequest($admin, $shared)->assertSessionHas('error');

        // Passing, then changing the version
        $version->planTestRuns($plan)->delete();
        $this->recordTestRun($version, VersionTestRunStatus::PASSED, $plan->refresh());
        $version->admin_path = '/changed-after-testing';
        $version->save();
        $this->assertSame('stale', $version->testGate($plan));
        $this->activationRequest($admin, $shared)->assertSessionHas('error');

        $this->assertSame(0, Task::where('action_slug', 'application_activate')->count());
    }

    private function assertActivatedInPlace(VersionTestRunStatus $status): void
    {
        [$admin, $shared, $version, $plan] = $this->readyToTest();
        $this->recordTestRun($version, $status, $plan);

        $this->activationRequest($admin, $shared)
            ->assertSessionDoesntHaveErrors()
            ->assertSessionHas('success');

        $this->assertSame('activating', $shared->refresh()->status);

        $task = Task::where('action_slug', 'application_activate')->firstOrFail();
        $this->assertSame($shared->id, $task->app_instance_id);
        // The instance that was added is the one deployed, not a second one
        $this->assertSame(1, AppInstance::where('label', 'My Shared App')->count());
    }

    public function test_a_shared_app_is_activated_in_place_once_its_plan_passed()
    {
        $this->assertActivatedInPlace(VersionTestRunStatus::PASSED);
    }

    public function test_a_shared_app_takes_fresh_settings_from_its_plan_when_it_is_activated()
    {
        [$admin, $shared, $version, $plan] = $this->readyToTest();

        // Nothing was copied to the instance when it was added
        $this->assertNull($shared->refresh()->setting('configurations.persistent-value'));
        $shared->updateSetting('left-over', 'from before it was activated');
        $plan->updateSettings(['configurations' => ['persistent-value' => 'set-on-the-plan']]);
        $plan->save();
        $this->recordTestRun($version, VersionTestRunStatus::PASSED, $plan->refresh());

        $this->activationRequest($admin, $shared)->assertSessionHas('success');

        $this->assertSame('set-on-the-plan', $shared->refresh()->setting('configurations.persistent-value'));
        $this->assertNull($shared->setting('left-over'));
    }

    public function test_the_chart_review_shows_the_configurations_the_plan_has_now_without_filling_in_the_instance()
    {
        [$admin, $shared, , $plan] = $this->readyToTest();
        $plan->updateSettings(['configurations' => ['persistent-value' => 'set-on-the-plan']]);
        $plan->save();

        $this->actingAs($admin)->get("/admin/organizations/{$shared->organization_id}/apps/{$shared->id}/helm-values")->assertOk();

        $this->assertNull($shared->refresh()->settings);
    }

    public function test_a_shared_app_is_activated_in_place_once_its_plan_tests_were_bypassed()
    {
        $this->assertActivatedInPlace(VersionTestRunStatus::BYPASSED);
    }

    public function test_a_shared_app_that_is_already_activating_cannot_be_activated_again()
    {
        [$admin, $shared, $version, $plan] = $this->readyToTest();
        $this->recordTestRun($version, VersionTestRunStatus::PASSED, $plan);
        $shared->status = 'activating';
        $shared->save();

        $this->activationRequest($admin, $shared)->assertSessionHas('error');

        $this->assertSame(0, Task::where('action_slug', 'application_activate')->count());
    }

    public function test_a_deployed_shared_app_that_was_deactivated_cannot_be_activated_as_if_new()
    {
        [$admin, $shared, $version, $plan] = $this->readyToTest();
        $this->recordTestRun($version, VersionTestRunStatus::PASSED, $plan);

        // It was activated before and deactivated since, so it is 'deactivated' again
        Task::forceCreate([
            'organization_id' => $shared->organization_id,
            'application_id' => $shared->application_id,
            'app_instance_id' => $shared->id,
            'action_group' => 'system',
            'action_slug' => 'application_activate',
            'status' => TaskStatus::COMPLETE,
        ]);

        $this->activationRequest($admin, $shared)->assertSessionHas('error');

        $this->assertSame(1, Task::where('action_slug', 'application_activate')->count());
    }

    public function test_every_page_reports_where_the_shared_app_stands_on_its_plan()
    {
        [$admin, $shared, $version, $plan] = $this->readyToTest();
        $page = "/admin/service/shared-apps/{$shared->id}";

        $this->actingAs($admin)->get($page)->assertInertia(fn ($inertia) => $inertia
            ->component('Admin/SharedApps/SharedAppView')
            ->where('shared_app.state', 'setup')
            ->where('shared_app.setup.servers_saved', true)
            ->where('shared_app.setup.gate', 'untested')
            ->where('shared_app.setup.can_activate', false)
            ->where('shared_app.setup.version.name', $version->name)
            ->where('shared_app.setup.run_url', null)
            // Servers are set, so the tests are next
            ->where('shared_app.setup.current_step', 4));

        // The version passing does not change what the page says about the plan
        $this->recordTestRun($version, VersionTestRunStatus::PASSED);
        $this->actingAs($admin)->get($page)->assertInertia(fn ($inertia) => $inertia
            ->where('shared_app.setup.gate', 'untested')
            ->where('shared_app.setup.can_activate', false));

        $run = $this->recordTestRun($version, VersionTestRunStatus::BYPASSED, $plan);
        $this->actingAs($admin)->get($page)->assertInertia(fn ($inertia) => $inertia
            ->where('shared_app.setup.gate', 'bypassed')
            ->where('shared_app.setup.can_activate', true)
            ->where('shared_app.setup.current_step', 5)
            ->where('shared_app.setup.run_url', "/admin/apps/{$shared->application->slug}/versions/{$version->name}/tests/{$run->id}"));
    }

    public function test_the_steps_start_at_the_servers_and_point_to_each_page()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $shared = $this->addSharedApp($admin, $app);
        $plan = $shared->plan;
        $plan_url = "/admin/apps/{$app->slug}/plans/{$plan->id}";

        $this->actingAs($admin)->get("/admin/service/shared-apps/{$shared->id}")->assertInertia(fn ($inertia) => $inertia
            ->where('shared_app.setup.servers_saved', false)
            // The version is the first step, and has been chosen already
            ->where('shared_app.setup.current_step', 1)
            ->where('shared_app.setup.version.id', $shared->version_id)
            ->where('shared_app.setup.servers', ['web' => null, 'database' => null, 'sso' => null])
            ->where('shared_app.setup.links.plan', "{$plan_url}/edit")
            ->where('shared_app.setup.links.configurations', "{$plan_url}/configurations")
            ->where('shared_app.setup.links.helm_values', "/admin/organizations/{$shared->organization_id}/apps/{$shared->id}/helm-values"));

        $web = Server::factory()->create(['type' => 'web', 'name' => 'web-one']);
        $this->giveServers($shared, $web);

        $this->actingAs($admin)->get("/admin/service/shared-apps/{$shared->id}")->assertInertia(fn ($inertia) => $inertia
            ->where('shared_app.setup.servers.web', 'web-one'));
    }

    public function test_the_setup_steps_sit_on_every_page_of_a_shared_app_until_it_is_activated()
    {
        [$admin, $shared, $version, $plan] = $this->readyToTest();
        $run = $this->recordTestRun($version, VersionTestRunStatus::PASSED, $plan);
        $app = $shared->application;
        $plan_url = "/admin/apps/{$app->slug}/plans/{$plan->id}";
        $pages = [
            "/admin/service/shared-apps/{$shared->id}",
            "/admin/service/shared-apps/{$shared->id}/edit",
            $plan_url,
            "{$plan_url}/edit",
            "{$plan_url}/features",
            "{$plan_url}/configurations",
            "{$plan_url}/secrets",
            "/admin/organizations/{$shared->organization_id}/apps/{$shared->id}",
            "/admin/organizations/{$shared->organization_id}/apps/{$shared->id}/helm-values",
            "/admin/apps/{$app->slug}/versions/{$version->name}/tests/{$run->id}",
        ];

        foreach ($pages as $page) {
            $this->actingAs($admin)->get($page)->assertInertia(fn ($inertia) => $inertia
                ->where('shared_app.id', $shared->id)
                ->where('shared_app.state', 'setup')
                ->where('shared_app.setup.version.id', $shared->version_id), $page);
        }

        // Once it is being activated, the steps are gone from all of them
        $shared->status = 'activating';
        $shared->save();

        foreach ($pages as $page) {
            $this->actingAs($admin)->get($page)->assertInertia(fn ($inertia) => $inertia
                ->where('shared_app.id', $shared->id)
                ->where('shared_app.state', 'active')
                ->where('shared_app.setup', null), $page);
        }
    }

    public function test_pages_that_are_not_a_shared_apps_have_no_shared_app()
    {
        $admin = $this->adminUser();
        $organization = Organization::where('type', '!=', 'shared')->firstOrFail();
        $app = Application::where('slug', 'demo_app')->first();
        $plan = AppPlan::factory()->create(['application_id' => $app->id, 'hidden' => false]);

        $this->actingAs($admin)->get("/admin/apps/{$app->slug}/plans/{$plan->id}")->assertInertia(fn ($inertia) => $inertia
            ->where('shared_app', null));
        $this->actingAs($admin)->get("/admin/organizations/{$organization->id}")->assertInertia(fn ($inertia) => $inertia
            ->where('shared_app', null));
    }

    public function test_the_steps_the_activation_page_points_to_can_be_opened()
    {
        [$admin, $shared, , $plan] = $this->readyToTest();
        $app = $shared->application;
        $plan_url = "/admin/apps/{$app->slug}/plans/{$plan->id}";

        $this->actingAs($admin)->get("{$plan_url}/edit")->assertOk();
        $this->actingAs($admin)->get("{$plan_url}/configurations")->assertOk();
        $this->actingAs($admin)->get("/admin/organizations/{$shared->organization_id}/apps/{$shared->id}/helm-values")->assertOk();
    }

    public function test_the_app_instance_has_nothing_to_edit_until_the_shared_app_is_activated()
    {
        [$admin, $shared] = $this->readyToTest();
        $show = "/admin/service/shared-apps/{$shared->id}";
        $instance = "/admin/organizations/{$shared->organization_id}/apps/{$shared->id}";

        $this->actingAs($admin)->get($show)->assertInertia(fn ($inertia) => $inertia
            ->where('shared_app.urls.instance', null)
            ->where('shared_app.urls.plan', "/admin/apps/{$shared->application->slug}/plans/{$shared->plan_id}")
            ->where('shared_app.urls.settings', "{$show}/edit"));
        $this->actingAs($admin)->get("{$instance}/edit")->assertRedirect($show)->assertSessionHas('error');
        $this->actingAs($admin)->put($instance, ['settings' => '{}'])->assertRedirect($show)->assertSessionHas('error');
        $this->assertNull($shared->refresh()->settings);

        $shared->status = 'activating';
        $shared->save();

        $this->actingAs($admin)->get($show)->assertInertia(fn ($inertia) => $inertia
            ->where('shared_app.urls.instance', $instance)
            ->where('shared_app.setup', null));
        $this->actingAs($admin)->get("{$instance}/edit")->assertOk();
    }

    public function test_the_shared_app_page_is_only_information_about_it()
    {
        [$admin, $shared, $version] = $this->readyToTest();
        $show = "/admin/service/shared-apps/{$shared->id}";

        // Not activated yet: no version or address of its own to report
        $this->actingAs($admin)->get($show)->assertInertia(fn ($inertia) => $inertia
            ->component('Admin/SharedApps/SharedAppView')
            ->where('app.state', 'setup')
            ->where('app.version', null));

        $shared->status = 'active';
        $shared->save();

        $this->actingAs($admin)->get($show)->assertInertia(fn ($inertia) => $inertia
            ->where('app.state', 'active')
            ->where('app.status', 'active')
            ->where('app.version', $version->name)
            ->where('app.domain', $shared->domain()));
    }

    public function test_the_version_is_chosen_while_the_shared_app_waits_to_be_set_up()
    {
        [$admin, $shared, $version, $plan] = $this->readyToTest();
        $other = AppVersion::factory()->create(['application_id' => $shared->application_id]);
        $url = "/admin/service/shared-apps/{$shared->id}/version";

        $this->actingAs($admin)->get("/admin/service/shared-apps/{$shared->id}")->assertInertia(fn ($inertia) => $inertia
            ->where('shared_app.setup.versions', fn ($versions) => collect($versions)->pluck('id')->contains($other->id)
                && collect($versions)->pluck('id')->contains($version->id)));

        $this->actingAs($admin)->put($url, ['version' => $other->id])->assertSessionDoesntHaveErrors()->assertSessionHas('success');
        $this->assertSame($other->id, $shared->refresh()->version_id);

        // The tests are of the version that was chosen
        $this->recordTestRun($version, VersionTestRunStatus::PASSED, $plan);
        $this->assertSame('untested', $other->testGate($plan->refresh()));

        // One of another app's is not an option
        $foreign = AppVersion::factory()->create(['application_id' => Application::factory()->create()->id]);
        $this->actingAs($admin)->put($url, ['version' => $foreign->id])->assertSessionHasErrors('version');
        $this->assertSame($other->id, $shared->refresh()->version_id);

        // And it is fixed once the app is on its way
        $shared->status = 'activating';
        $shared->save();
        $this->actingAs($admin)->put($url, ['version' => $version->id])->assertSessionHas('error');
        $this->assertSame($other->id, $shared->refresh()->version_id);
    }

    public function test_a_shared_app_can_be_left_to_be_managed_externally_with_only_a_domain()
    {
        [$admin, $shared] = $this->readyToTest();
        $show = "/admin/service/shared-apps/{$shared->id}";
        $subdomain = OrgSubdomain::factory()->create(['organization_id' => Organization::where('type', 'superaccount')->firstOrFail()->id]);

        // A domain is the one thing it needs
        $this->actingAs($admin)->post("{$show}/external", [])->assertSessionHasErrors('domain');
        $this->assertSame('deactivated', $shared->refresh()->status);

        // No servers, version tests or configuration come into it
        $this->actingAs($admin)->post("{$show}/external", ['domain' => $subdomain->id])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect($show)
            ->assertSessionHas('success');

        $shared->refresh();
        $this->assertTrue($shared->isManagedExternally());
        $this->assertSame('active', $shared->status);
        $this->assertSame($subdomain->id, $shared->primary_domain_id);
        // Users come through the central LDAP groups
        Bus::assertDispatched(AddLdapGroups::class, fn (AddLdapGroups $job) => $job->app_instance->is($shared));
        $this->assertSame(0, Task::where('action_slug', 'application_activate')->count());

        $this->actingAs($admin)->get($show)->assertInertia(fn ($inertia) => $inertia
            ->where('app.state', 'external')
            ->where('app.domain', $subdomain->name)
            ->where('app.domain_chosen', true)
            ->where('shared_app.setup', null)
            ->where('shared_app.urls.plan', null)
            ->where('shared_app.urls.instance', null));

        // And it can't be activated, tested or chosen a version for after that
        $this->activationRequest($admin, $shared)->assertSessionHas('error');
        $this->actingAs($admin)->post("{$show}/tests")->assertSessionHas('error');
        $this->actingAs($admin)->post("{$show}/external", ['domain' => $subdomain->id])->assertSessionHas('error');
        $this->assertSame(0, VersionTestRun::count());
    }

    public function test_a_shared_apps_label_and_domain_are_changed_from_its_settings()
    {
        [$admin, $shared] = $this->readyToTest();
        $show = "/admin/service/shared-apps/{$shared->id}";
        $subdomain = OrgSubdomain::factory()->create(['organization_id' => Organization::where('type', 'superaccount')->firstOrFail()->id]);

        $this->actingAs($admin)->get("{$show}/edit")->assertInertia(fn ($inertia) => $inertia
            ->component('Admin/SharedApps/SharedAppEdit')
            ->where('app.label', 'My Shared App')
            ->where('app.domain', 0));

        $this->actingAs($admin)->put($show, ['label' => 'Renamed', 'domain' => $subdomain->id])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect($show);

        $this->assertSame('Renamed', $shared->refresh()->label);
        $this->assertSame($subdomain->id, $shared->primary_domain_id);
        // Still waiting to be activated
        $this->assertSame('deactivated', $shared->status);
    }

    public function test_a_test_run_of_a_shared_apps_plan_leads_back_to_it()
    {
        [$admin, $shared, $version, $plan] = $this->readyToTest();
        $run = $this->recordTestRun($version, VersionTestRunStatus::PASSED, $plan);

        $this->actingAs($admin)->get("/admin/apps/{$shared->application->slug}/versions/{$version->name}/tests/{$run->id}")
            ->assertInertia(fn ($inertia) => $inertia
                ->where('breadcrumbs.1.url', "/admin/service/shared-apps/{$shared->id}")
                ->where('shared_app.id', $shared->id));
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

    /**
     * A shared app of an app that has no registered profile, like GitLab.
     *
     * @return array{0: User, 1: AppInstance}
     */
    private function genericSharedApp(): array
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $app->source = ApplicationSource::Generic;
        $app->save();

        return [$admin, $this->addSharedApp($admin, $app)];
    }

    public function test_a_shared_app_without_a_registered_profile_is_registered_as_active_with_its_ldap_groups()
    {
        [, $shared] = $this->genericSharedApp();

        // Nothing will activate it, and the apps shared from it need it active
        $this->assertSame('active', $shared->status);
        $this->assertTrue($shared->isManagedExternally());
        $this->assertNull($shared->plan->web_server_id);
        $this->assertSame(0, Task::where('action_slug', 'application_activate')->count());
        Bus::assertDispatched(AddLdapGroups::class, fn (AddLdapGroups $job) => $job->app_instance->is($shared));
    }

    public function test_a_shared_app_with_a_registered_profile_waits_for_activation_and_its_ldap_groups()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $shared = $this->addSharedApp($admin, Application::where('slug', 'demo_app')->first());

        $this->assertSame('deactivated', $shared->status);
        // Activating it creates them
        Bus::assertNotDispatched(AddLdapGroups::class);
    }

    public function test_a_shared_app_without_a_registered_profile_cannot_be_activated_through_kumulicp()
    {
        [$admin, $shared] = $this->genericSharedApp();
        $plan = $shared->plan;
        $version = $shared->version;

        $this->actingAs($admin)->get("/admin/service/shared-apps/{$shared->id}")->assertInertia(fn ($page) => $page
            ->where('app.state', 'external')
            ->where('shared_app.setup', null));

        // Even with everything else in place: servers and a passed run on its plan
        $plan->web_server_id = Server::factory()->create(['type' => 'web'])->id;
        $plan->save();
        $this->recordTestRun($version, VersionTestRunStatus::PASSED, $plan);

        $this->actingAs($admin)->post("/admin/service/shared-apps/{$shared->id}/tests")->assertSessionHas('error');
        $this->actingAs($admin)->post("/admin/service/shared-apps/{$shared->id}/tests/bypass", ['reason' => 'Because', 'acknowledge' => true])->assertSessionHas('error');
        $this->activationRequest($admin, $shared)->assertSessionHas('error');

        $this->assertSame('active', $shared->refresh()->status);
        $this->assertSame(0, Task::where('action_slug', 'application_activate')->count());
        $this->assertSame(1, VersionTestRun::count());
    }

    public function test_a_hidden_plan_has_full_settings_only_when_the_app_has_a_registered_profile()
    {
        $profile = Application::factory()->create(['source' => ApplicationSource::Profile]);
        $generic = Application::factory()->create(['source' => ApplicationSource::Generic]);

        $this->assertTrue(AppPlan::factory()->create(['application_id' => $profile->id, 'hidden' => true])->hasFullSettings());
        $this->assertFalse(AppPlan::factory()->create(['application_id' => $generic->id, 'hidden' => true])->hasFullSettings());
        // A plan customers can pick always has them, whatever the source
        $this->assertTrue(AppPlan::factory()->create(['application_id' => $generic->id, 'hidden' => false])->hasFullSettings());
    }

    public function test_full_settings_do_not_depend_on_the_shared_app_being_activated()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $app->source = ApplicationSource::Profile;
        $app->save();
        $shared = $this->addSharedApp($admin, $app);
        $plan = $shared->plan;
        $base = "/admin/apps/{$app->slug}/plans/{$plan->id}";

        // Not activated, no servers: still has everything because it is a profile app
        $this->actingAs($admin)->get($base)->assertInertia(fn ($page) => $page->where('plan.full_settings', true));
        $this->actingAs($admin)->get("{$base}/features")->assertOk();
        $this->actingAs($admin)->get("{$base}/configurations")->assertOk();
        $this->actingAs($admin)->get("{$base}/secrets")->assertOk();
    }

    public function test_a_generic_apps_shared_plan_hides_the_settings_pages_even_when_activated()
    {
        $admin = $this->adminUser();
        $this->sharedOrganization();
        $app = Application::where('slug', 'demo_app')->first();
        $app->source = ApplicationSource::Generic;
        $app->save();
        $shared = $this->addSharedApp($admin, $app);
        $plan = $shared->plan;
        $plan->web_server_id = Server::factory()->create(['type' => 'web'])->id;
        $plan->save();
        $base = "/admin/apps/{$app->slug}/plans/{$plan->id}";

        $this->actingAs($admin)->get($base)->assertInertia(fn ($page) => $page->where('plan.full_settings', false));
        $this->actingAs($admin)->get("{$base}/features")->assertNotFound();
        $this->actingAs($admin)->put("{$base}/features", [])->assertNotFound();
        $this->actingAs($admin)->get("{$base}/configurations")->assertNotFound();
        $this->actingAs($admin)->put("{$base}/configurations", [])->assertNotFound();
        $this->actingAs($admin)->get("{$base}/secrets")->assertNotFound();
        $this->actingAs($admin)->put("{$base}/secrets", [])->assertNotFound();
        $this->actingAs($admin)->post("{$base}/secrets/reveal", [])->assertNotFound();
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
