<?php

namespace App\Http\Controllers\Admin\Applications\Versions;

use App\AppInstance;
use App\Application;
use App\AppVersion;
use App\Enums\VersionTestStepStage;
use App\Enums\VersionTestStepStatus;
use App\Exceptions\VersionTestException;
use App\Http\Controllers\Controller;
use App\Services\VersionTesting\VersionTestRunner;
use App\Support\Facades\Application as ApplicationFacade;
use App\VersionTestEvent;
use App\VersionTestRun;
use App\VersionTestStep;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class Tests extends Controller
{
    public function __construct(protected VersionTestRunner $runner) {}

    /**
     * Every test run of a version, newest first.
     */
    public function index(Application $app, AppVersion $version)
    {
        return inertia()->render('Admin/Applications/Versions/VersionTests', [
            'app' => $this->appProps($app),
            'version' => $this->versionProps($version),
            'runs' => $version->testRuns()->with(['started_by', 'bypassed_by'])->withCount('steps')->get()->map(fn (VersionTestRun $run) => [
                'id' => $run->id,
                'status' => $run->status->value,
                'started_by' => $run->started_by?->name,
                'steps' => $run->steps_count,
                'created_at' => $run->created_at->format('Y-m-d H:i:s'),
                'finished_at' => $run->finished_at?->format('Y-m-d H:i:s'),
            ]),
            'active_run' => $this->activeRunProps(),
            'breadcrumbs' => $this->breadcrumbs($app, $version, __('admin.version_tests.tests')),
        ]);
    }

    /**
     * One run: its steps, what the admin has to do next, and everything that happened.
     */
    public function show(Application $app, AppVersion $version, VersionTestRun $run)
    {
        $this->ensureBelongs($version, $run);

        // Catches a provisioning task that failed while nobody was looking
        $this->runner->syncProvisioning();
        $run->refresh();

        $steps = $run->steps()->with(['plan', 'from_version'])->get();
        $has_active_step = $steps->contains(fn (VersionTestStep $step) => $step->status->isActive());
        $current = $steps->first(fn (VersionTestStep $step) => $step->status === VersionTestStepStatus::AWAITING_CONFIRMATION);
        // A shared app's own stage has no address when it has no site of its own to log in to
        $failing = $steps->first(fn (VersionTestStep $step) => $step->status === VersionTestStepStatus::NEEDS_ATTENTION);
        $hidden = $current?->stage === VersionTestStepStage::CORE && ! $current->app_url;

        return inertia()->render('Admin/Applications/Versions/VersionTestRun', [
            'app' => $this->appProps($app),
            'version' => $this->versionProps($version),
            'run' => [
                'id' => $run->id,
                'status' => $run->status->value,
                'baseline' => $run->baseline_version?->name,
                'started_by' => $run->started_by?->name,
                'bypassed_by' => $run->bypassed_by?->name,
                'bypass_reason' => $run->bypass_reason,
                // A shared app's plan is tested in two stages: the shared app, then a site added to it
                'staged' => (bool) $run->app_plan_id,
                'created_at' => $run->created_at->format('Y-m-d H:i:s'),
                'finished_at' => $run->finished_at?->format('Y-m-d H:i:s'),
                'gate' => $version->testGate($run->plan),
            ],
            'steps' => $steps->map(fn (VersionTestStep $step) => [
                'id' => $step->id,
                'position' => $step->position,
                'type' => $step->type->value,
                'plan' => $step->plan->name,
                'from_version' => $step->from_version?->name,
                'status' => $step->status->value,
                'stage' => $step->stage?->value,
                'failure_reason' => $step->failure_reason,
            ]),
            // Credentials are only sent while the admin has something to try out. A shared
            // app that is only a container has nothing to log in to until a site is added.
            'current_step' => $current ? [
                'id' => $current->id,
                'plan' => $current->plan->name,
                'type' => $current->type->value,
                'stage' => $current->stage?->value,
                'app_url' => $current->app_url,
                // The org's own user, who signs in through LDAP
                'username' => $hidden ? null : $current->test_username,
                'password' => $hidden ? null : $current->test_password,
                // The app's local admin, which works even when LDAP sign-in is broken
                'admin_account' => $hidden ? null : $this->adminAccount($current),
            ] : null,
            // A step that failed to set up and is waiting for the admin to fix it, ignore it or give up
            'failing_step' => $failing ? [
                'id' => $failing->id,
                'plan' => $failing->plan->name,
                'stage' => $failing->stage?->value,
                'reason' => $failing->failure_reason,
                'task_id' => $failing->task_id,
            ] : null,
            'can' => [
                'start_next' => $run->isRunning() && ! $has_active_step && $steps->contains(fn (VersionTestStep $step) => $step->status === VersionTestStepStatus::PENDING),
                'cancel' => $run->isRunning() && ! $steps->contains(fn (VersionTestStep $step) => in_array($step->status, [VersionTestStepStatus::PROVISIONING, VersionTestStepStatus::CLEARING], true)),
                'retry_step_id' => ! $has_active_step ? $steps->first(fn (VersionTestStep $step) => $step->status === VersionTestStepStatus::FAILED)?->id : null,
            ],
            // The page refreshes itself while a test org is being built or deleted
            'polling' => $steps->contains(fn (VersionTestStep $step) => in_array($step->status, [VersionTestStepStatus::PROVISIONING, VersionTestStepStatus::NEEDS_ATTENTION, VersionTestStepStatus::CLEARING], true)),
            'events' => $run->events()->with(['user', 'step.plan'])->get()->map(fn (VersionTestEvent $event) => [
                'id' => $event->id,
                'type' => $event->type,
                'message' => $event->message,
                'context' => $event->context ?? [],
                'plan' => $event->step?->plan->name,
                'step_type' => $event->step?->type->value,
                'user' => $event->user?->name,
                'created_at' => $event->created_at->format('Y-m-d H:i:s'),
            ]),
            'breadcrumbs' => $this->runBreadcrumbs($app, $version, $run),
        ]);
    }

    /**
     * The local admin of the step's app, so a broken LDAP sign-in can be told
     * apart from a broken app.
     *
     * @return array{username: string, password: string}|null
     */
    private function adminAccount(VersionTestStep $step): ?array
    {
        // The site added to a shared app is what is logged in to, not the shared app
        $app_instance_id = $step->site_app_instance_id ?? $step->app_instance_id;
        $app_instance = $app_instance_id ? AppInstance::find($app_instance_id) : null;
        if (! $app_instance) {
            return null;
        }

        return [
            'username' => ApplicationFacade::profile($app_instance->application)->adminUsername($app_instance),
            'password' => $app_instance->api_password(),
        ];
    }

    public function store(Application $app, AppVersion $version)
    {
        return $this->attempt(function () use ($app, $version) {
            $run = $this->runner->start($version, auth()->user());

            return redirect($this->runUrl($app, $version, $run))->with('success', __('admin.version_tests.started'));
        }, $this->versionUrl($app, $version));
    }

    public function next(Application $app, AppVersion $version, VersionTestRun $run)
    {
        $this->ensureBelongs($version, $run);

        return $this->attempt(function () use ($app, $version, $run) {
            $this->runner->startNext($run, auth()->user());

            return redirect($this->runUrl($app, $version, $run))->with('success', __('admin.version_tests.next_started'));
        }, $this->runUrl($app, $version, $run));
    }

    public function confirm(Application $app, AppVersion $version, VersionTestRun $run, VersionTestStep $step)
    {
        $this->ensureBelongs($version, $run, $step);

        return $this->attempt(function () use ($app, $version, $run, $step) {
            $this->runner->confirmWorking($step, auth()->user());

            return redirect($this->runUrl($app, $version, $run))->with('success', __('admin.version_tests.confirmed'));
        }, $this->runUrl($app, $version, $run));
    }

    public function fail(Request $request, Application $app, AppVersion $version, VersionTestRun $run, VersionTestStep $step)
    {
        $this->ensureBelongs($version, $run, $step);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        return $this->attempt(function () use ($app, $version, $run, $step, $validated) {
            $this->runner->markFailed($step, auth()->user(), $validated['reason'] ?? null);

            return redirect($this->runUrl($app, $version, $run))->with('success', __('admin.version_tests.marked_failed'));
        }, $this->runUrl($app, $version, $run));
    }

    public function retry(Application $app, AppVersion $version, VersionTestRun $run, VersionTestStep $step)
    {
        $this->ensureBelongs($version, $run, $step);

        return $this->attempt(function () use ($app, $version, $run, $step) {
            $this->runner->retryStep($step, auth()->user());

            return redirect($this->runUrl($app, $version, $run))->with('success', __('admin.version_tests.retried'));
        }, $this->runUrl($app, $version, $run));
    }

    public function ignore(Application $app, AppVersion $version, VersionTestRun $run, VersionTestStep $step)
    {
        $this->ensureBelongs($version, $run, $step);

        return $this->attempt(function () use ($app, $version, $run, $step) {
            $this->runner->ignoreFailure($step, auth()->user());

            return redirect($this->runUrl($app, $version, $run))->with('success', __('admin.version_tests.ignored'));
        }, $this->runUrl($app, $version, $run));
    }

    public function abandon(Application $app, AppVersion $version, VersionTestRun $run, VersionTestStep $step)
    {
        $this->ensureBelongs($version, $run, $step);

        return $this->attempt(function () use ($app, $version, $run, $step) {
            $this->runner->abandon($step, auth()->user());

            return redirect($this->runUrl($app, $version, $run))->with('success', __('admin.version_tests.abandoned'));
        }, $this->runUrl($app, $version, $run));
    }

    public function cancel(Application $app, AppVersion $version, VersionTestRun $run)
    {
        $this->ensureBelongs($version, $run);

        return $this->attempt(function () use ($app, $version, $run) {
            $this->runner->cancel($run, auth()->user());

            return redirect($this->runUrl($app, $version, $run))->with('success', __('admin.version_tests.cancelled'));
        }, $this->runUrl($app, $version, $run));
    }

    public function bypass(Request $request, Application $app, AppVersion $version)
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:1000',
            'acknowledge' => 'accepted',
        ]);

        return $this->attempt(function () use ($app, $version, $validated) {
            $this->runner->bypass($version, auth()->user(), $validated['reason']);

            return redirect($this->versionUrl($app, $version))->with('success', __('admin.version_tests.bypassed', ['version' => $version->name]));
        }, $this->versionUrl($app, $version));
    }

    /**
     * Run an action on the runner, turning a refusal into a message for the admin.
     */
    private function attempt(callable $action, string $back): RedirectResponse
    {
        try {
            return $action();
        } catch (VersionTestException $e) {
            return redirect($back)->with('error', $e->getMessage());
        }
    }

    private function ensureBelongs(AppVersion $version, VersionTestRun $run, ?VersionTestStep $step = null): void
    {
        abort_unless($run->app_version_id === $version->id, 404);
        abort_unless($step === null || $step->version_test_run_id === $run->id, 404);
    }

    private function versionUrl(Application $app, AppVersion $version): string
    {
        return '/admin/apps/'.$app->slug.'/versions/'.$version->name;
    }

    private function runUrl(Application $app, AppVersion $version, VersionTestRun $run): string
    {
        return $this->versionUrl($app, $version).'/tests/'.$run->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function appProps(Application $app): array
    {
        return ['id' => $app->id, 'name' => $app->name, 'slug' => $app->slug];
    }

    /**
     * @return array<string, mixed>
     */
    private function versionProps(AppVersion $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->name,
            'gate' => $version->testGate(),
        ];
    }

    /**
     * The run that holds the single test slot, if there is one.
     *
     * @return array<string, mixed>|null
     */
    private function activeRunProps(): ?array
    {
        $run = $this->runner->activeRun();

        return $run ? [
            'id' => $run->id,
            'app' => $run->application->name,
            'version' => $run->version->name,
            'url' => $this->runUrl($run->application, $run->version, $run),
        ] : null;
    }

    /**
     * A run of a shared app's plan belongs to that shared app, so it leads back
     * there. Any other run belongs to its version.
     *
     * @return array<int, array<string, string>>
     */
    private function runBreadcrumbs(Application $app, AppVersion $version, VersionTestRun $run): array
    {
        $shared_app = $run->app_plan_id ? AppInstance::where('plan_id', $run->app_plan_id)->first() : null;

        if ($shared_app) {
            return [
                ['url' => '/admin/service/shared-apps', 'label' => __('admin.shared_apps.shared_apps')],
                ['url' => '/admin/service/shared-apps/'.$shared_app->id, 'label' => $shared_app->label],
                ['url' => '/admin/service/shared-apps/'.$shared_app->id.'/activation', 'label' => __('admin.shared_apps.activation')],
                ['label' => __('admin.version_tests.run_number', ['id' => $run->id])],
            ];
        }

        return [
            ...$this->breadcrumbs($app, $version, null),
            ['url' => '/admin/apps/'.$app->slug.'/versions/'.$version->name.'/tests', 'label' => __('admin.version_tests.tests')],
            ['label' => __('admin.version_tests.run_number', ['id' => $run->id])],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function breadcrumbs(Application $app, AppVersion $version, ?string $last): array
    {
        $crumbs = [
            ['url' => '/admin/apps', 'label' => __('admin.applications.apps')],
            ['label' => $app->name, 'url' => '/admin/apps/'.$app->slug],
            ['url' => '/admin/apps/'.$app->slug.'/versions', 'label' => __('admin.applications.versions.versions')],
            ['url' => $this->versionUrl($app, $version), 'label' => $version->name],
        ];

        if ($last !== null) {
            $crumbs[] = ['label' => $last];
        }

        return $crumbs;
    }
}
