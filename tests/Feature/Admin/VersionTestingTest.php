<?php

namespace Tests\Feature\Admin;

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Enums\VersionTestRunStatus;
use App\Enums\VersionTestStepStatus;
use App\Enums\VersionTestStepType;
use App\Exceptions\VersionTestException;
use App\Organization;
use App\Services\VersionTesting\VersionTestRunner;
use App\Support\Facades\Action;
use App\Task;
use App\User;
use App\VersionTestRun;
use App\VersionTestStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $next_task_id = 900;
        Action::shouldReceive('execute')->andReturnUsing(function () use (&$next_task_id) {
            $this->executed_actions++;

            $task = new Task;
            $task->id = $next_task_id++;

            return $task;
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
        $run->fingerprint = (new \App\Support\VersionTesting\VersionFingerprint)->for($version);
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

    public function test_version_test_orgs_do_not_clutter_the_adhoc_test_list()
    {
        $run = $this->runner()->start($this->candidate, $this->admin);
        $step = $run->steps->first();
        $adhoc = new \App\AccountTest;
        $adhoc->organization_id = 1;
        $adhoc->created_by_id = $this->admin->id;
        $adhoc->test_number = 1;
        $adhoc->description = 'A manual test';
        $adhoc->settings = ['base_plan' => '', 'apps' => []];
        $adhoc->status = 'pending';
        $adhoc->save();

        $this->actingAs($this->admin)->get('/admin/server/tests')->assertInertia(fn ($page) => $page
            ->where('tests', fn ($tests) => collect($tests)->pluck('id')->all() === [$adhoc->id])
        );
    }
}
