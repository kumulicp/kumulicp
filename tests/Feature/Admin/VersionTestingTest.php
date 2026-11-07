<?php

namespace Tests\Feature\Admin;

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Enums\TaskStatus;
use App\Enums\VersionTestRunStatus;
use App\Enums\VersionTestStepStatus;
use App\Enums\VersionTestStepType;
use App\Exceptions\VersionTestException;
use App\Organization;
use App\Plan;
use App\Services\VersionTesting\VersionTestRunner;
use App\Support\Facades\Action;
use App\Support\VersionTesting\VersionFingerprint;
use App\Task;
use App\User;
use App\VersionTestRun;
use App\VersionTestStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\TestSupports;
use Tests\TestCase;

class VersionTestingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Application $application;

    private AppVersion $enabled;

    private AppVersion $candidate;

    // How many actions the code under test queued
    private int $executed_actions = 0;

    protected function setUp(): void
    {
        parent::setUp();

        (new TestSupports)->seed();
        $this->admin = User::find(1);

        $this->application = Application::factory()->create();
        $this->enabled = AppVersion::factory()->create(['application_id' => $this->application->id, 'name' => '1.0', 'status' => 'active']);
        $this->candidate = AppVersion::factory()->create(['application_id' => $this->application->id, 'name' => '2.0', 'status' => 'deactivated']);
        AppPlan::factory()->count(2)->create(['application_id' => $this->application->id]);

        // Provisioning and clearing are separate tasks, covered by their own tests
        Action::shouldReceive('execute')->andReturnUsing(function () {
            $this->executed_actions++;

            return Task::forceCreate([
                'organization_id' => 1,
                'action_group' => 'system',
                'action_slug' => 'fake',
                'status' => TaskStatus::IN_PROGRESS,
            ]);
        });
    }

    private function runner(): VersionTestRunner
    {
        return app(VersionTestRunner::class);
    }

    private function approve(AppVersion $version, VersionTestRunStatus $status = VersionTestRunStatus::PASSED): VersionTestRun
    {
        $run = new VersionTestRun;
        $run->application_id = $version->application_id;
        $run->app_version_id = $version->id;
        $run->status = $status;
        $run->fingerprint = (new VersionFingerprint)->for($version);
        $run->finished_at = now();
        $run->save();

        return $run;
    }

    /**
     * Take the active step through "it worked" and its org being cleared.
     */
    private function passActiveStep(VersionTestRun $run): void
    {
        $step = $run->steps()->whereIn('status', VersionTestStepStatus::activeValues())->firstOrFail();

        if ($step->status === VersionTestStepStatus::PROVISIONING) {
            $this->runner()->provisioned($step->id);
        }

        $this->runner()->confirmWorking($step->refresh(), $this->admin);
        $this->runner()->cleared($step->id, 'passed');
    }

    // ─── The gate ────────────────────────────────────────────────────────────

    public function test_an_untested_version_cannot_be_enabled()
    {
        $this->assertSame('untested', $this->candidate->testGate());

        $response = $this->actingAs($this->admin)->get("/admin/apps/{$this->application->slug}/versions/2.0/enable");

        $response->assertRedirect("/admin/apps/{$this->application->slug}/versions/2.0");
        $response->assertSessionHas('error');
        $this->assertSame('deactivated', $this->candidate->refresh()->status);
        $this->assertSame('active', $this->enabled->refresh()->status);
    }

    public function test_a_passed_version_can_be_enabled_and_enabling_does_not_upgrade_existing_apps()
    {
        $this->approve($this->candidate);

        $response = $this->actingAs($this->admin)->get("/admin/apps/{$this->application->slug}/versions/2.0/enable");

        $response->assertSessionHas('success');
        $this->assertSame('active', $this->candidate->refresh()->status);
        $this->assertSame('deactivated', $this->enabled->refresh()->status);
        // Enabling must not queue the mass upgrade; it has its own button
        $this->assertSame(0, $this->executed_actions);
    }

    public function test_a_bypassed_version_can_be_enabled()
    {
        $this->approve($this->candidate, VersionTestRunStatus::BYPASSED);

        $this->assertSame('bypassed', $this->candidate->testGate());
        $this->assertTrue($this->candidate->canBeEnabled());
    }

    public function test_changing_a_plan_after_a_pass_makes_the_version_stale()
    {
        $this->approve($this->candidate);
        $this->assertTrue($this->candidate->canBeEnabled());

        $plan = AppPlan::where('application_id', $this->application->id)->first();
        $plan->updateSetting('base.price', 25);

        $this->assertSame('stale', $this->candidate->refresh()->testGate());
        $this->assertFalse($this->candidate->canBeEnabled());
    }

    public function test_the_daily_update_check_does_not_make_a_version_stale()
    {
        $this->approve($this->candidate);

        $this->candidate->update_check = ['checked_at' => now()->toIso8601String()];
        $this->candidate->save();

        $this->assertTrue($this->candidate->refresh()->canBeEnabled());
    }

    public function test_the_application_enable_path_is_gated_too()
    {
        $response = $this->actingAs($this->admin)->post("/admin/apps/{$this->application->slug}/enable", ['version' => $this->candidate->id]);

        $response->assertSessionHas('error');
        $this->assertSame('deactivated', $this->candidate->refresh()->status);
    }

    // ─── The runner ──────────────────────────────────────────────────────────

    public function test_a_run_tests_every_plan_for_a_fresh_install_and_an_upgrade()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);

        $steps = $run->steps;
        $this->assertCount(4, $steps);
        $this->assertSame(
            ['fresh_install', 'upgrade', 'fresh_install', 'upgrade'],
            $steps->map(fn (VersionTestStep $step) => $step->type->value)->all()
        );
        $this->assertTrue($steps->where('type', VersionTestStepType::UPGRADE)->every(fn (VersionTestStep $step) => $step->from_version_id === $this->enabled->id));
        $this->assertSame($this->enabled->id, $run->baseline_version_id);
    }

    public function test_only_the_first_step_starts_and_the_rest_wait_for_the_admin()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);

        $this->assertSame(
            ['provisioning', 'pending', 'pending', 'pending'],
            $run->steps->map(fn (VersionTestStep $step) => $step->status->value)->all()
        );
        $this->assertNotNull($run->steps->first()->test_password);
        $this->assertNotNull($run->steps->first()->account_test_id);
        $this->assertSame('run_started', $run->events()->get()->last()->type);
    }

    public function test_without_an_enabled_version_only_fresh_installs_are_tested()
    {
        $this->enabled->status = 'deactivated';
        $this->enabled->save();

        $run = $this->runner()->start($this->candidate, $this->admin);

        $this->assertCount(2, $run->steps);
        $this->assertTrue($run->steps->every(fn (VersionTestStep $step) => $step->type === VersionTestStepType::FRESH_INSTALL));
        $this->assertNull($run->baseline_version_id);
    }

    public function test_only_one_run_can_be_active_at_a_time()
    {
        $this->runner()->start($this->candidate, $this->admin);

        $other = AppVersion::factory()->create(['application_id' => $this->application->id, 'name' => '3.0', 'status' => 'deactivated']);

        $this->expectException(VersionTestException::class);
        $this->runner()->start($other, $this->admin);
    }

    public function test_the_next_test_cannot_start_until_the_current_org_is_cleared()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);
        $step = $run->steps->first();

        try {
            $this->runner()->startNext($run, $this->admin);
            $this->fail('The next test started while another was active');
        } catch (VersionTestException) {
            // Provisioning
        }

        $this->runner()->provisioned($step->id);
        $this->assertSame(VersionTestStepStatus::AWAITING_CONFIRMATION, $step->refresh()->status);

        try {
            $this->runner()->startNext($run, $this->admin);
            $this->fail('The next test started before the admin confirmed');
        } catch (VersionTestException) {
            // Awaiting confirmation
        }

        $this->runner()->confirmWorking($step, $this->admin);
        $this->assertSame(VersionTestStepStatus::CLEARING, $step->refresh()->status);

        try {
            $this->runner()->startNext($run, $this->admin);
            $this->fail('The next test started while the org was still being cleared');
        } catch (VersionTestException) {
            // Clearing
        }

        $this->runner()->cleared($step->id, 'passed');
        $this->assertSame(VersionTestStepStatus::PASSED, $step->refresh()->status);

        $next = $this->runner()->startNext($run, $this->admin);
        $this->assertSame(2, $next->position);
        $this->assertSame(VersionTestStepStatus::PROVISIONING, $next->status);
    }

    public function test_a_step_cannot_be_confirmed_twice()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);
        $step = $run->steps->first();
        $this->runner()->provisioned($step->id);

        $this->runner()->confirmWorking($step, $this->admin);

        $this->expectException(VersionTestException::class);
        $this->runner()->confirmWorking($step, $this->admin);
    }

    public function test_passing_every_step_passes_the_run_and_lets_the_version_be_enabled()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);

        for ($position = 1; $position <= 4; $position++) {
            $this->passActiveStep($run);

            if ($position < 4) {
                $this->assertSame(VersionTestRunStatus::RUNNING, $run->refresh()->status);
                $this->runner()->startNext($run, $this->admin);
            }
        }

        $this->assertSame(VersionTestRunStatus::PASSED, $run->refresh()->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame('passed', $this->candidate->refresh()->testGate());
        $this->assertTrue($this->candidate->canBeEnabled());
    }

    public function test_marking_a_step_failed_fails_the_run_once_the_org_is_cleared()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);
        $step = $run->steps->first();
        $this->runner()->provisioned($step->id);

        $this->runner()->markFailed($step, $this->admin, 'Login page is blank');
        $this->assertSame(VersionTestRunStatus::RUNNING, $run->refresh()->status);

        $this->runner()->cleared($step->id, 'failed');

        $this->assertSame(VersionTestRunStatus::FAILED, $run->refresh()->status);
        $this->assertSame(VersionTestStepStatus::FAILED, $step->refresh()->status);
        $this->assertSame('Login page is blank', $step->failure_reason);
        $this->assertSame('failed', $this->candidate->refresh()->testGate());
    }

    public function test_a_provisioning_failure_clears_the_org_and_fails_the_run()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);
        $step = $run->steps->first();

        $this->runner()->provisioningFailed($step->id, 'Helm release timed out');
        $this->assertSame(VersionTestStepStatus::CLEARING, $step->refresh()->status);

        $this->runner()->cleared($step->id, 'failed');

        $this->assertSame(VersionTestRunStatus::FAILED, $run->refresh()->status);
        $this->assertContains('provisioning_failed', $run->events()->pluck('type')->all());
    }

    public function test_a_failed_step_can_be_tried_again()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);
        $step = $run->steps->first();
        $this->runner()->provisioningFailed($step->id, 'Helm release timed out');
        $this->runner()->cleared($step->id, 'failed');

        $this->runner()->retryStep($step->refresh(), $this->admin);

        $this->assertSame(VersionTestRunStatus::RUNNING, $run->refresh()->status);
        $this->assertSame(VersionTestStepStatus::PROVISIONING, $step->refresh()->status);
        $this->assertNull($step->failure_reason);
    }

    public function test_every_stage_is_logged()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);
        $step = $run->steps->first();
        $this->runner()->provisioned($step->id);
        $this->runner()->confirmWorking($step, $this->admin);
        $this->runner()->cleared($step->id, 'passed');

        $this->assertSame(
            ['run_started', 'step_started', 'ready_for_testing', 'confirmed_working', 'org_cleared'],
            $run->events()->reorder('id')->pluck('type')->all()
        );
    }

    public function test_a_run_with_a_test_org_waiting_can_be_cancelled()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);
        $step = $run->steps->first();
        $this->runner()->provisioned($step->id);

        $this->runner()->cancel($run, $this->admin);

        $this->assertSame(VersionTestRunStatus::CANCELLED, $run->refresh()->status);
        $this->assertSame(VersionTestStepStatus::CLEARING, $step->refresh()->status);
        $this->assertSame('untested', $this->candidate->refresh()->testGate());
    }

    public function test_a_run_cannot_be_cancelled_while_its_org_is_being_set_up()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);

        $this->expectException(VersionTestException::class);
        $this->runner()->cancel($run, $this->admin);
    }

    // ─── Bypass ──────────────────────────────────────────────────────────────

    public function test_bypassing_requires_a_reason_and_an_acknowledgement()
    {
        $url = "/admin/apps/{$this->application->slug}/versions/2.0/tests/bypass";

        $this->actingAs($this->admin)->post($url, ['reason' => '', 'acknowledge' => true])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post($url, ['reason' => 'Hotfix for a security issue', 'acknowledge' => false])->assertSessionHasErrors('acknowledge');
        $this->assertSame(0, VersionTestRun::count());
    }

    public function test_bypassing_is_recorded_and_lets_the_version_be_enabled()
    {
        $response = $this->actingAs($this->admin)->post("/admin/apps/{$this->application->slug}/versions/2.0/tests/bypass", [
            'reason' => 'Hotfix for a security issue',
            'acknowledge' => true,
        ]);

        $response->assertSessionHas('success');
        $run = VersionTestRun::firstOrFail();
        $this->assertSame(VersionTestRunStatus::BYPASSED, $run->status);
        $this->assertSame('Hotfix for a security issue', $run->bypass_reason);
        $this->assertSame($this->admin->id, $run->bypassed_by_id);
        $this->assertSame('bypassed', $run->events()->first()->type);
        $this->assertTrue($this->candidate->refresh()->canBeEnabled());
    }

    // ─── Pages ───────────────────────────────────────────────────────────────

    public function test_the_run_page_shows_the_credentials_only_while_a_step_awaits_confirmation()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);
        $step = $run->steps->first();
        $url = "/admin/apps/{$this->application->slug}/versions/2.0/tests/{$run->id}";

        $this->actingAs($this->admin)->get($url)->assertInertia(fn ($page) => $page
            ->component('Admin/Applications/Versions/VersionTestRun')
            ->where('current_step', null)
            ->where('polling', true)
            ->where('can.start_next', false)
        );

        $this->runner()->provisioned($step->id);

        $this->actingAs($this->admin)->get($url)->assertInertia(fn ($page) => $page
            ->where('current_step.id', $step->id)
            ->where('current_step.password', $step->refresh()->test_password)
            ->where('polling', false)
        );
    }

    public function test_the_run_page_gives_the_local_admin_account_alongside_the_ldap_one()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);
        $step = $run->steps->first();
        $app_instance = $this->installOn(Organization::first(), $this->candidate);
        $app_instance->api_password = Crypt::encrypt('local-admin-pw');
        $app_instance->save();
        $step->app_instance_id = $app_instance->id;
        $step->save();

        $this->runner()->provisioned($step->id);

        $this->actingAs($this->admin)
            ->get("/admin/apps/{$this->application->slug}/versions/2.0/tests/{$run->id}")
            ->assertInertia(fn ($page) => $page
                ->where('current_step.admin_account.username', 'support')
                ->where('current_step.admin_account.password', 'local-admin-pw')
                ->where('current_step.password', $step->refresh()->test_password)
            );
    }

    public function test_the_local_admin_account_is_the_one_the_app_says_it_has()
    {
        // The demo profile is the one that names its admin
        $this->application->slug = 'demo_app';
        $this->application->save();
        \App\Support\Facades\Application::register(new DemoAppProfile);
        $run = $this->runner()->start($this->candidate, $this->admin);
        $step = $run->steps->first();
        $app_instance = $this->installOn(Organization::first(), $this->candidate);
        $plan = $app_instance->plan;
        $plan->updateSettings(['admin_username' => 'Administrator']);
        $plan->save();
        $step->app_instance_id = $app_instance->id;
        $step->save();

        $this->runner()->provisioned($step->id);

        $this->actingAs($this->admin)
            ->get("/admin/apps/{$this->application->slug}/versions/2.0/tests/{$run->id}")
            ->assertInertia(fn ($page) => $page->where('current_step.admin_account.username', 'Administrator'));
    }

    public function test_the_next_test_button_appears_once_the_previous_org_is_cleared()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);
        $this->passActiveStep($run);

        $this->actingAs($this->admin)
            ->get("/admin/apps/{$this->application->slug}/versions/2.0/tests/{$run->id}")
            ->assertInertia(fn ($page) => $page->where('can.start_next', true));
    }

    public function test_a_run_cannot_be_driven_through_another_version()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);

        $this->actingAs($this->admin)
            ->get("/admin/apps/{$this->application->slug}/versions/1.0/tests/{$run->id}")
            ->assertNotFound();
    }

    // ─── Mass upgrade ────────────────────────────────────────────────────────

    private function installOn(Organization $organization, AppVersion $version): AppInstance
    {
        return AppInstance::factory()->create([
            'application_id' => $this->application->id,
            'organization_id' => $organization->id,
            'version_id' => $version->id,
            'plan_id' => AppPlan::where('application_id', $this->application->id)->value('id'),
        ]);
    }

    public function test_the_mass_upgrade_button_counts_only_real_customers_on_an_older_version()
    {
        $this->candidate->status = 'active';
        $this->candidate->save();
        $this->enabled->status = 'deactivated';
        $this->enabled->save();

        $customer = Organization::factory()->create();
        $test_org = Organization::factory()->create(['account_test_id' => 12]);
        $this->installOn($customer, $this->enabled);
        $this->installOn($test_org, $this->enabled);

        $this->actingAs($this->admin)
            ->get("/admin/apps/{$this->application->slug}/versions")
            ->assertInertia(fn ($page) => $page
                ->where('mass_upgrade.version', '2.0')
                ->where('mass_upgrade.count', 1)
            );
    }

    public function test_there_is_no_mass_upgrade_when_every_app_is_up_to_date()
    {
        $customer = Organization::factory()->create();
        $this->installOn($customer, $this->enabled);

        $this->actingAs($this->admin)
            ->get("/admin/apps/{$this->application->slug}/versions")
            ->assertInertia(fn ($page) => $page->where('mass_upgrade.count', 0));

        $this->actingAs($this->admin)
            ->post("/admin/apps/{$this->application->slug}/versions/1.0/mass-upgrade")
            ->assertSessionHas('error');
    }

    public function test_versions_that_are_numerically_lower_are_found_not_just_alphabetically_lower()
    {
        $this->candidate->name = '10.0';
        $this->candidate->status = 'active';
        $this->candidate->save();
        $this->enabled->name = '9.0';
        $this->enabled->status = 'deactivated';
        $this->enabled->save();
        $this->installOn(Organization::factory()->create(), $this->enabled);

        $this->assertCount(1, $this->application->instancesBehind($this->candidate));
    }

    public function test_a_version_that_is_not_enabled_cannot_be_rolled_out()
    {
        $this->actingAs($this->admin)
            ->post("/admin/apps/{$this->application->slug}/versions/2.0/mass-upgrade")
            ->assertSessionHas('error');
    }

    // ─── Test organizations ──────────────────────────────────────────────────

    public function test_test_organizations_are_hidden_from_the_admin_organizations_list()
    {
        $customer = Organization::factory()->create(['name' => 'A Real Customer']);
        $test_org = Organization::factory()->create(['name' => 'test11', 'account_test_id' => 3]);

        $this->actingAs($this->admin)->get('/admin/organizations')->assertInertia(fn ($page) => $page
            ->where('organizations', fn ($organizations) => collect($organizations)->pluck('id')->contains($customer->id)
                && ! collect($organizations)->pluck('id')->contains($test_org->id)
            )
        );
    }

    public function test_admins_cannot_open_the_pages_of_a_test_organization()
    {
        $test_org = Organization::factory()->create(['slug' => 'test11', 'account_test_id' => 3]);

        foreach (['', '/logs', '/tasks', '/apps', '/domains', '/backups', '/reactivate', '/deactivate'] as $path) {
            $this->actingAs($this->admin)->get("/admin/organizations/{$test_org->id}{$path}")->assertNotFound();
        }

        // The routes that don't bind the organization get a slug
        $this->actingAs($this->admin)->get('/admin/organizations/test11/reactivate')->assertNotFound();
    }

    public function test_an_app_of_a_test_organization_cannot_be_reached_through_another_organization()
    {
        $customer = Organization::factory()->create();
        $test_org = Organization::factory()->create(['account_test_id' => 3]);
        $app = $this->installOn($test_org, $this->enabled);

        $this->actingAs($this->admin)->get("/admin/organizations/{$customer->id}/apps/{$app->id}")->assertNotFound();
    }

    // ─── Overview ────────────────────────────────────────────────────────────

    public function test_the_overview_lists_runs_in_progress_with_their_stats()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);
        $step = $run->steps->first();
        $this->runner()->provisioned($step->id);

        $this->actingAs($this->admin)->get('/admin/server/tests')->assertInertia(fn ($page) => $page
            ->component('Admin/Tests/TestsOverview')
            ->where('stats.running', 1)
            ->where('stats.awaiting', 1)
            ->where('stats.passed', 0)
            ->where('runs.0.id', $run->id)
            ->where('runs.0.version', $this->candidate->name)
            ->where('runs.0.url', "/admin/apps/{$this->application->slug}/versions/{$this->candidate->name}/tests/{$run->id}")
            ->where('runs.0.progress.total', $run->steps()->count())
            ->where('runs.0.current_step.status', 'awaiting_confirmation')
        );
    }

    public function test_the_overview_counts_recent_outcomes_and_leaves_finished_runs_out_of_the_list()
    {
        $this->runner()->bypass($this->candidate, $this->admin, 'Hotfix for a security issue');
        $old = $this->runner()->bypass($this->candidate, $this->admin, 'An old bypass');
        $old->finished_at = now()->subDays(45);
        $old->save();

        $this->actingAs($this->admin)->get('/admin/server/tests')->assertInertia(fn ($page) => $page
            ->where('stats.running', 0)
            ->where('stats.bypassed', 1)
            ->where('runs', [])
        );
    }

    public function test_the_overview_lists_untested_versions_newer_than_the_enabled_one()
    {
        $this->actingAs($this->admin)->get('/admin/server/tests')->assertInertia(fn ($page) => $page
            ->where('stats.untested', 1)
            ->where('untested_versions.0.id', $this->candidate->id)
            ->where('untested_versions.0.gate', 'untested')
            ->where('untested_versions.0.url', "/admin/apps/{$this->application->slug}/versions/{$this->candidate->name}/tests")
        );

        $this->runner()->bypass($this->candidate, $this->admin, 'Hotfix for a security issue');

        $this->actingAs($this->admin)->get('/admin/server/tests')->assertInertia(fn ($page) => $page
            ->where('stats.untested', 0)
            ->where('untested_versions', [])
        );
    }

    public function test_the_overview_leaves_out_versions_of_apps_that_are_not_enabled()
    {
        $this->application->enabled = false;
        $this->application->save();

        $this->actingAs($this->admin)->get('/admin/server/tests')->assertInertia(fn ($page) => $page
            ->where('stats.untested', 0)
            ->where('untested_versions', [])
        );
    }

    // ─── Runs for one plan (a shared app's own plan) ─────────────────────────

    private function sharedPlan(): AppPlan
    {
        return AppPlan::factory()->create(['application_id' => $this->application->id, 'hidden' => true, 'web_server_id' => 1]);
    }

    public function test_a_plan_run_tests_only_that_plan_with_a_fresh_install()
    {
        $plan = $this->sharedPlan();

        // The enabled version differs from the candidate, so a version run would also upgrade
        $run = $this->runner()->start($this->candidate, $this->admin, $plan);

        $this->assertSame($plan->id, $run->app_plan_id);
        $this->assertNull($run->baseline_version_id);
        $this->assertSame([$plan->id], $run->steps->pluck('app_plan_id')->all());
        $this->assertSame(['fresh_install'], $run->steps->map(fn (VersionTestStep $step) => $step->type->value)->all());
    }

    public function test_a_plan_and_its_version_are_judged_separately()
    {
        $plan = $this->sharedPlan();
        $run = $this->approve($this->candidate);

        $this->assertSame('passed', $this->candidate->testGate());
        $this->assertSame('untested', $this->candidate->testGate($plan));
        $this->assertFalse($this->candidate->canBeActivatedOn($plan));

        $run->delete();
        $plan_run = $this->approve($this->candidate, VersionTestRunStatus::BYPASSED);
        $plan_run->app_plan_id = $plan->id;
        $plan_run->fingerprint = (new VersionFingerprint)->forPlan($this->candidate, $plan);
        $plan_run->save();

        $this->assertSame('bypassed', $this->candidate->testGate($plan));
        $this->assertTrue($this->candidate->canBeActivatedOn($plan));
        $this->assertSame('untested', $this->candidate->testGate());
        $this->assertFalse($this->candidate->canBeEnabled());
    }

    public function test_a_plan_result_goes_stale_when_that_plan_changes_but_not_when_another_does()
    {
        $plan = $this->sharedPlan();
        $this->runner()->bypass($this->candidate, $this->admin, 'Trusted', $plan);
        $this->assertSame('bypassed', $this->candidate->testGate($plan));

        AppPlan::factory()->create(['application_id' => $this->application->id]);
        $this->assertSame('bypassed', $this->candidate->testGate($plan));

        $plan->web_server_id = 2;
        $plan->save();
        $this->assertSame('stale', $this->candidate->testGate($plan->refresh()));
    }

    public function test_bypassing_a_plan_leaves_a_running_version_run_alone()
    {
        $plan = $this->sharedPlan();
        $running = $this->runner()->start($this->candidate, $this->admin);

        $bypass = $this->runner()->bypass($this->candidate, $this->admin, 'Trusted', $plan);

        $this->assertSame($plan->id, $bypass->app_plan_id);
        $this->assertSame(VersionTestRunStatus::RUNNING, $running->refresh()->status);
        $this->assertSame('running', $this->candidate->testGate());
        $this->assertSame('bypassed', $this->candidate->testGate($plan));
    }

    public function test_plan_runs_do_not_appear_among_the_versions_runs()
    {
        $plan = $this->sharedPlan();
        $this->runner()->bypass($this->candidate, $this->admin, 'Trusted', $plan);

        $this->assertSame(0, $this->candidate->testRuns()->count());
        $this->assertSame(1, $this->candidate->planTestRuns($plan)->count());
    }

    // ─── The base plan the test organization is given ────────────────────────

    private function sharedOrganizationOn(Plan $plan): Organization
    {
        return Organization::factory()->create(['slug' => 'shared', 'type' => 'shared', 'plan_id' => $plan->id]);
    }

    public function test_a_version_run_uses_the_default_plan()
    {
        $default = Plan::where('is_default', 1)->firstOrFail();
        $this->sharedOrganizationOn(Plan::factory()->create(['is_default' => 0]));

        $this->assertTrue($this->runner()->basePlanFor()->is($default));

        $run = $this->runner()->start($this->candidate, $this->admin);

        $this->assertSame($default->id, $run->steps->first()->account_test->setting('base_plan'));
    }

    public function test_a_shared_apps_plan_run_uses_the_plan_the_shared_apps_organization_is_on()
    {
        $shared_plan = Plan::factory()->create(['is_default' => 0]);
        $this->sharedOrganizationOn($shared_plan);
        $plan = $this->sharedPlan();

        $this->assertTrue($this->runner()->basePlanFor($plan)->is($shared_plan));

        $run = $this->runner()->start($this->candidate, $this->admin, $plan);

        $this->assertSame($shared_plan->id, $run->steps->first()->account_test->setting('base_plan'));
    }

    public function test_a_shared_apps_plan_run_works_without_any_default_plan()
    {
        $shared_plan = Plan::factory()->create(['is_default' => 0]);
        $this->sharedOrganizationOn($shared_plan);
        Plan::query()->update(['is_default' => 0]);

        $run = $this->runner()->start($this->candidate, $this->admin, $this->sharedPlan());

        $this->assertSame($shared_plan->id, $run->steps->first()->account_test->setting('base_plan'));
    }

    public function test_a_shared_apps_plan_run_falls_back_to_the_default_plan_when_shared_apps_has_none()
    {
        $default = Plan::where('is_default', 1)->firstOrFail();

        $this->assertTrue($this->runner()->basePlanFor($this->sharedPlan())->is($default));
    }

    public function test_a_run_will_not_start_when_there_is_no_plan_to_give_the_test_organization()
    {
        Plan::query()->update(['is_default' => 0]);

        foreach ([null, $this->sharedPlan()] as $plan) {
            try {
                $this->runner()->start($this->candidate, $this->admin, $plan);
                $this->fail('The run started without a plan for the test organization.');
            } catch (VersionTestException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        // Nothing was left half-made
        $this->assertSame(0, VersionTestRun::count());
        $this->assertSame(0, $this->executed_actions);
    }

    // ─── The test organization itself ────────────────────────────────────────

    public function test_a_test_organization_is_created_with_the_columns_the_database_requires()
    {
        $account_test = new AccountTest;
        $account_test->organization_id = 1;
        $account_test->created_by_id = $this->admin->id;
        $account_test->test_number = 1;
        $account_test->description = 'A version test';
        $account_test->settings = ['base_plan' => null, 'apps' => []];
        $account_test->status = 'in_progress';
        $account_test->save();

        [$organization] = (new CreateTestOrganization)->create($account_test, Plan::where('is_default', 1)->firstOrFail(), CreateTestOrganization::lastNumber() + 1);
        $organization->refresh();

        // MariaDB rejects an organization without settings, and these have no default
        $this->assertSame([], $organization->settings);
        $this->assertNotSame('', (string) $organization->email);
        $this->assertNotSame('', (string) $organization->phone_number);
        $this->assertSame($account_test->id, $organization->account_test_id);
    }
}
