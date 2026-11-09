<?php

namespace App\Services\VersionTesting;

use App\AccountTest;
use App\Actions\Tests\ClearVersionTestStep;
use App\Actions\Tests\ProvisionVersionTestSite;
use App\Actions\Tests\ProvisionVersionTestStep;
use App\AppInstance;
use App\AppPlan;
use App\AppVersion;
use App\Enums\TaskStatus;
use App\Enums\VersionTestRunStatus;
use App\Enums\VersionTestStepStage;
use App\Enums\VersionTestStepStatus;
use App\Enums\VersionTestStepType;
use App\Exceptions\VersionTestException;
use App\Notifications\VersionTestReady;
use App\Notifications\VersionTestRunFinished;
use App\Notifications\VersionTestStepFailed;
use App\Organization;
use App\Plan;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Application as ApplicationFacade;
use App\Support\VersionTesting\VersionFingerprint;
use App\Task;
use App\User;
use App\VersionTestEvent;
use App\VersionTestRun;
use App\VersionTestStep;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drives a version test run. Every change to a run or step goes through here
 * so it is checked, logged and, where an admin needs to know, notified.
 *
 * Only one test is ever active: a step holds the slot from the moment it starts
 * provisioning until its org has been deleted again, and no other step or run
 * can start before then.
 */
class VersionTestRunner
{
    /**
     * Start testing a version. The first step starts straight away.
     *
     * With a plan, only that plan is tested, with a fresh install: this is how a
     * shared app's own hidden plan is tested, since it is not one of the plans
     * customers can pick and there is nothing to upgrade from.
     *
     * @throws VersionTestException
     */
    public function start(AppVersion $version, User $user, ?AppPlan $plan = null): VersionTestRun
    {
        $plans = $plan
            ? new Collection([$plan])
            : AppPlan::where('application_id', $version->application_id)
                ->where('archive', 0)
                ->visible()
                ->orderBy('display_order')
                ->orderBy('id')
                ->get();

        if ($plans->isEmpty()) {
            throw new VersionTestException(__('admin.version_tests.errors.no_plans'));
        }

        // Found out now, not by every step failing once it is queued
        if (! $this->basePlanFor($plan)) {
            throw new VersionTestException(__($plan ? 'admin.version_tests.errors.no_shared_base_plan' : 'admin.version_tests.errors.no_default_plan'));
        }

        $run = DB::transaction(function () use ($version, $user, $plans, $plan) {
            $this->assertSlotFree();

            $baseline = $plan ? null : $version->application->active_version();
            if ($baseline && $baseline->is($version)) {
                $baseline = null;
            }

            $run = new VersionTestRun;
            $run->application_id = $version->application_id;
            $run->app_version_id = $version->id;
            $run->app_plan_id = $plan?->id;
            $run->baseline_version_id = $baseline?->id;
            $run->status = VersionTestRunStatus::RUNNING;
            $run->fingerprint = $plan ? (new VersionFingerprint)->forPlan($version, $plan) : (new VersionFingerprint)->for($version);
            $run->started_by_id = $user->id;
            $run->save();

            $position = 1;
            foreach ($plans as $plan) {
                $this->createStep($run, $position++, VersionTestStepType::FRESH_INSTALL, $plan);

                if ($baseline) {
                    $this->createStep($run, $position++, VersionTestStepType::UPGRADE, $plan, $baseline);
                }
            }

            $this->log($run, 'run_started', user: $user, context: [
                'version' => $version->name,
                'steps' => $position - 1,
                'baseline' => $baseline?->name,
            ]);

            if (! $baseline && ! $plan) {
                $this->log($run, 'no_baseline', user: $user);
            }

            return $run;
        });

        $this->startStep($run->steps()->first(), $user);

        return $run->refresh();
    }

    /**
     * The base plan a test organization is given. A shared app's own plan is
     * tested under the plan the shared apps organization is on, as it will run;
     * everything else is tested under the default plan.
     */
    public function basePlanFor(?AppPlan $plan = null): ?Plan
    {
        if ($plan) {
            $shared_plan = Organization::where('type', 'shared')->first()?->plan;

            if ($shared_plan) {
                return $shared_plan;
            }
        }

        return Plan::where('is_default', 1)->first();
    }

    /**
     * Start the next step. The admin asks for it once the last one is cleared.
     *
     * @throws VersionTestException
     */
    public function startNext(VersionTestRun $run, User $user): VersionTestStep
    {
        $step = DB::transaction(function () use ($run) {
            $run = VersionTestRun::lockForUpdate()->findOrFail($run->id);

            if (! $run->isRunning()) {
                throw new VersionTestException(__('admin.version_tests.errors.not_running'));
            }

            $this->assertSlotFree($run);

            $step = $run->steps()->where('status', VersionTestStepStatus::PENDING->value)->first();
            if (! $step) {
                throw new VersionTestException(__('admin.version_tests.errors.no_next_step'));
            }

            return $step;
        });

        $this->startStep($step, $user);

        return $step->refresh();
    }

    /**
     * The admin tried the app and it works. The step passes once its org is gone.
     *
     * For a shared app, this confirms the shared app itself. It is not done
     * until a site has been added to it and tried, so the org is kept and the
     * site is added instead.
     */
    public function confirmWorking(VersionTestStep $step, User $user): void
    {
        $adding_site = $step->stage === VersionTestStepStage::CORE;

        if (! $this->moveStep($step, [VersionTestStepStatus::AWAITING_CONFIRMATION], $adding_site ? VersionTestStepStatus::PROVISIONING : VersionTestStepStatus::CLEARING, [
            'confirmed_at' => now(),
            'confirmed_by_id' => $user->id,
            // The provisioning task is done; the site's own is saved once it has started
            ...($adding_site ? ['stage' => VersionTestStepStage::SITE, 'task_id' => null] : []),
        ])) {
            throw new VersionTestException(__('admin.version_tests.errors.not_awaiting'));
        }

        if ($adding_site) {
            $this->log($step->run, 'core_confirmed', $step, $user);
            $this->startSite($step);

            return;
        }

        $this->log($step->run, 'confirmed_working', $step, $user);
        $this->clear($step, ClearVersionTestStep::PASSED);
    }

    /**
     * The admin tried the app and it doesn't work. The run fails once the org is gone.
     */
    public function markFailed(VersionTestStep $step, User $user, ?string $reason = null): void
    {
        if (! $this->moveStep($step, [VersionTestStepStatus::AWAITING_CONFIRMATION], VersionTestStepStatus::CLEARING, [
            'confirmed_at' => now(),
            'confirmed_by_id' => $user->id,
            'failure_reason' => $reason,
        ])) {
            throw new VersionTestException(__('admin.version_tests.errors.not_awaiting'));
        }

        $this->log($step->run, 'marked_failed', $step, $user, $reason);
        $this->clear($step, ClearVersionTestStep::FAILED);
    }

    /**
     * Run a failed step again, with a fresh test org.
     *
     * @throws VersionTestException
     */
    public function retryStep(VersionTestStep $step, User $user): VersionTestStep
    {
        DB::transaction(function () use ($step) {
            $run = VersionTestRun::lockForUpdate()->findOrFail($step->version_test_run_id);

            if ($run->status !== VersionTestRunStatus::FAILED || $step->status !== VersionTestStepStatus::FAILED) {
                throw new VersionTestException(__('admin.version_tests.errors.not_retryable'));
            }

            $this->assertSlotFree();

            $run->status = VersionTestRunStatus::RUNNING;
            $run->finished_at = null;
            $run->save();

            $step->status = VersionTestStepStatus::PENDING;
            $step->failure_reason = null;
            $step->confirmed_at = null;
            $step->confirmed_by_id = null;
            $step->app_url = null;
            $step->stage = null;
            $step->site_app_instance_id = null;
            $step->site_plan_id = null;
            $step->account_test_id = null;
            $step->save();
        });

        $this->log($step->run, 'step_retried', $step, $user);
        $this->startStep($step, $user);

        return $step->refresh();
    }

    /**
     * Give up on a run. A test org that is being tried out is cleared first.
     *
     * @throws VersionTestException
     */
    public function cancel(VersionTestRun $run, User $user): void
    {
        $this->cancelRun($run, $user, 'cancelled');
    }

    /**
     * Enable a version without testing it. Recorded as a run of its own so the
     * version page says who skipped the tests, when and why.
     *
     * With a plan, only the tests of that plan are bypassed.
     *
     * @throws VersionTestException
     */
    public function bypass(AppVersion $version, User $user, string $reason, ?AppPlan $plan = null): VersionTestRun
    {
        $running = ($plan ? $version->planTestRuns($plan) : $version->testRuns())->where('status', VersionTestRunStatus::RUNNING->value)->first();
        if ($running) {
            $this->cancelRun($running, $user, 'cancelled_by_bypass');
        }

        $run = new VersionTestRun;
        $run->application_id = $version->application_id;
        $run->app_version_id = $version->id;
        $run->app_plan_id = $plan?->id;
        $run->status = VersionTestRunStatus::BYPASSED;
        $run->fingerprint = $plan ? (new VersionFingerprint)->forPlan($version, $plan) : (new VersionFingerprint)->for($version);
        $run->started_by_id = $user->id;
        $run->bypassed_by_id = $user->id;
        $run->bypass_reason = $reason;
        $run->finished_at = now();
        $run->save();

        $this->log($run, 'bypassed', user: $user, message: $reason);

        return $run;
    }

    /**
     * The task that provisions a step's org finished: the app is ready to be tried.
     */
    public function provisioned(int $step_id): void
    {
        $step = VersionTestStep::find($step_id);
        if (! $step) {
            return;
        }

        $app_instance = $step->app_instance_id ? AppInstance::find($step->app_instance_id) : null;

        // A shared app is tried out in two stages: the shared app itself, then a site added to it.
        // One that is only a container for sites has no address or login of its own to try.
        $shared = (bool) ($step->run->app_plan_id && $app_instance);
        $has_site = $app_instance && ApplicationFacade::profile($app_instance->application)->hasOwnSite($app_instance);

        $domain = $shared && ! $has_site ? null : $app_instance?->domain();

        if (! $this->moveStep($step, [VersionTestStepStatus::PROVISIONING, VersionTestStepStatus::NEEDS_ATTENTION], VersionTestStepStatus::AWAITING_CONFIRMATION, [
            'ready_at' => now(),
            'app_url' => $domain ? 'https://'.$domain : null,
            'stage' => $shared ? VersionTestStepStage::CORE : null,
            'failure_reason' => null,
        ])) {
            return;
        }

        $this->log($step->run, 'ready_for_testing', $step);
        $this->notify(new VersionTestReady($step->refresh()));
    }

    /**
     * The task that adds a site to a shared app finished: the site is ready to be tried.
     */
    public function siteProvisioned(int $step_id): void
    {
        $step = VersionTestStep::find($step_id);
        if (! $step) {
            return;
        }

        $site = $step->site_app_instance_id ? AppInstance::find($step->site_app_instance_id) : null;
        $domain = $site?->domain();

        if (! $this->moveStep($step, [VersionTestStepStatus::PROVISIONING, VersionTestStepStatus::NEEDS_ATTENTION], VersionTestStepStatus::AWAITING_CONFIRMATION, [
            'ready_at' => now(),
            'app_url' => $domain ? 'https://'.$domain : null,
            'failure_reason' => null,
        ])) {
            return;
        }

        $this->log($step->run, 'site_ready_for_testing', $step);
        $this->notify(new VersionTestReady($step->refresh()));
    }

    /**
     * Setting up a step failed. The test org is kept, not cleared, so the admin can
     * fix the cause and retry the task, ignore the failure if it was a false alarm,
     * or give up on the step.
     *
     * @param  bool  $fixable  False when there is no task to retry, so there is nothing to fix: the org is cleared and the run fails
     */
    public function provisioningFailed(int $step_id, string $reason, bool $fixable = true): void
    {
        $step = VersionTestStep::find($step_id);
        if (! $step) {
            return;
        }

        $to = $fixable ? VersionTestStepStatus::NEEDS_ATTENTION : VersionTestStepStatus::CLEARING;

        if (! $this->moveStep($step, [VersionTestStepStatus::PROVISIONING], $to, ['failure_reason' => $reason])) {
            return;
        }

        $this->log($step->run, 'provisioning_failed', $step, message: $reason);
        $this->notify(new VersionTestStepFailed($step->refresh()));

        if (! $fixable) {
            $this->clear($step, ClearVersionTestStep::FAILED);
        }
    }

    /**
     * The failure was a false alarm: carry on as if the task had finished.
     *
     * @throws VersionTestException
     */
    public function ignoreFailure(VersionTestStep $step, User $user): void
    {
        $step->refresh();

        if ($step->status !== VersionTestStepStatus::NEEDS_ATTENTION) {
            throw new VersionTestException(__('admin.version_tests.errors.not_failing'));
        }

        $at_site = $step->stage === VersionTestStepStage::SITE;

        // The app the admin would try out has to exist
        if (! ($at_site ? $step->site_app_instance_id : $step->app_instance_id)) {
            throw new VersionTestException(__('admin.version_tests.errors.nothing_to_test'));
        }

        $this->log($step->run, 'failure_ignored', $step, $user, $step->failure_reason);

        $at_site ? $this->siteProvisioned($step->id) : $this->provisioned($step->id);
    }

    /**
     * Give up on a step that failed to set up. The run fails once the org is gone.
     *
     * @throws VersionTestException
     */
    public function abandon(VersionTestStep $step, User $user): void
    {
        if (! $this->moveStep($step, [VersionTestStepStatus::NEEDS_ATTENTION], VersionTestStepStatus::CLEARING, [
            'confirmed_at' => now(),
            'confirmed_by_id' => $user->id,
        ])) {
            throw new VersionTestException(__('admin.version_tests.errors.not_failing'));
        }

        $this->log($step->run, 'step_abandoned', $step, $user, $step->failure_reason);
        $this->clear($step, ClearVersionTestStep::FAILED);
    }

    /**
     * A step's org has been deleted: the step gets its outcome, and the run
     * either carries on, waiting for the admin to start the next step, or ends.
     */
    public function cleared(int $step_id, string $outcome): void
    {
        $step = VersionTestStep::find($step_id);
        if (! $step) {
            return;
        }

        $status = $outcome === ClearVersionTestStep::PASSED ? VersionTestStepStatus::PASSED : VersionTestStepStatus::FAILED;

        if (! $this->moveStep($step, [VersionTestStepStatus::CLEARING], $status)) {
            return;
        }

        $run = $step->run;
        $this->log($run, 'org_cleared', $step);

        // Made for the test site, and nothing else uses it now the org is gone
        if ($step->site_plan_id) {
            AppPlan::whereKey($step->site_plan_id)->delete();
        }

        // The step's own task is left over from provisioning when it failed
        if ($status === VersionTestStepStatus::FAILED && $step->task_id) {
            Task::whereKey($step->task_id)->whereIn('status', [TaskStatus::FAILED->value, TaskStatus::BLOCKED->value])->delete();
        }

        if (! $run->isRunning()) {
            return;
        }

        if ($status === VersionTestStepStatus::FAILED) {
            $this->finishRun($run, VersionTestRunStatus::FAILED, 'run_failed');
        } elseif (! $run->steps()->where('status', VersionTestStepStatus::PENDING->value)->exists()) {
            $this->finishRun($run, VersionTestRunStatus::PASSED, 'run_passed');
        }
    }

    /**
     * Fail steps whose provisioning task has failed or got stuck behind a failed
     * one. A blocked task isn't run again, so nothing else would notice. Steps
     * whose task finished without the step being told are moved on.
     */
    public function syncProvisioning(): void
    {
        $steps = VersionTestStep::whereIn('status', [VersionTestStepStatus::PROVISIONING->value, VersionTestStepStatus::NEEDS_ATTENTION->value])->get();

        foreach ($steps as $step) {
            // The task id is saved just after the step starts; until then there is nothing to check
            if (! $step->task_id) {
                continue;
            }

            $task = Task::find($step->task_id);
            $parked = $step->status === VersionTestStepStatus::NEEDS_ATTENTION;

            // The task finished but the step never heard, or the admin fixed it and retried it:
            // what it does on completing may also have thrown (a database error, say). Both are
            // safe to repeat.
            if ($task?->status === TaskStatus::COMPLETE) {
                $step->stage === VersionTestStepStage::SITE ? $this->siteProvisioned($step->id) : $this->provisioned($step->id);

                continue;
            }

            $broken = ! $task || in_array($task->status, [TaskStatus::FAILED, TaskStatus::BLOCKED], true);

            if (! $broken) {
                // Retried by the admin: it is being set up again
                if ($parked && $this->moveStep($step, [VersionTestStepStatus::NEEDS_ATTENTION], VersionTestStepStatus::PROVISIONING, ['failure_reason' => null])) {
                    $this->log($step->run, 'step_resumed', $step);
                }

                continue;
            }

            // Already told the admin; it stays as it is until they fix it or decide
            if ($parked) {
                continue;
            }

            $reason = $task
                ? ($task->dependencies()->where('status', TaskStatus::FAILED->value)->value('error_message') ?: $task->error_message)
                : null;

            $this->provisioningFailed($step->id, $reason ?: __('messages.exception.version_test_provisioning_failed'));
        }
    }

    /**
     * Whether a test is active anywhere: another run is going, or a test org is
     * still being built, tried or deleted.
     */
    public function activeRun(): ?VersionTestRun
    {
        return VersionTestRun::where('status', VersionTestRunStatus::RUNNING->value)->first();
    }

    /**
     * @param  VersionTestRun|null  $continuing  The run the caller is carrying on with, which is allowed to be running
     *
     * @throws VersionTestException
     */
    private function assertSlotFree(?VersionTestRun $continuing = null): void
    {
        $running = VersionTestRun::where('status', VersionTestRunStatus::RUNNING->value)
            ->when($continuing, fn ($query) => $query->whereKeyNot($continuing->id))
            ->lockForUpdate()
            ->first();

        if ($running) {
            throw new VersionTestException(__('admin.version_tests.errors.run_in_progress', [
                'app' => $running->application->name,
                'version' => $running->version->name,
            ]));
        }

        if (VersionTestStep::whereIn('status', VersionTestStepStatus::activeValues())->exists()) {
            throw new VersionTestException(__('admin.version_tests.errors.step_in_progress'));
        }
    }

    private function createStep(VersionTestRun $run, int $position, VersionTestStepType $type, AppPlan $plan, ?AppVersion $from = null): VersionTestStep
    {
        $step = new VersionTestStep;
        $step->version_test_run_id = $run->id;
        $step->position = $position;
        $step->type = $type;
        $step->app_plan_id = $plan->id;
        $step->from_version_id = $from?->id;
        $step->status = VersionTestStepStatus::PENDING;
        $step->save();

        return $step;
    }

    private function startStep(VersionTestStep $step, User $user): void
    {
        $run = $step->run;

        $account_test = new AccountTest;
        $account_test->organization_id = Organization::where('type', 'superaccount')->value('id');
        $account_test->created_by_id = $user->id;
        $account_test->test_number = 1;
        $account_test->description = __('admin.version_tests.account_test_description', [
            'app' => $run->application->name,
            'version' => $run->version->name,
            'plan' => $step->plan->name,
            'type' => __('admin.version_tests.types.'.$step->type->value),
        ]);
        $account_test->status = 'in_progress';
        $account_test->settings = ['base_plan' => $this->basePlanFor($run->plan)?->id, 'apps' => []];
        $account_test->save();

        $moved = $this->moveStep($step, [VersionTestStepStatus::PENDING], VersionTestStepStatus::PROVISIONING, [
            'account_test_id' => $account_test->id,
            'test_password' => Str::password(16, true, true, false, false),
            'started_at' => now(),
        ]);

        if (! $moved) {
            $account_test->delete();

            return;
        }

        $this->log($run, 'step_started', $step, $user);

        try {
            $task = ActionFacade::execute(new ProvisionVersionTestStep($step));
        } catch (Throwable $e) {
            report($e);
            $task = null;
        }

        if (! $task) {
            $this->provisioningFailed($step->id, __('messages.exception.version_test_provisioning_failed'), fixable: false);

            return;
        }

        $step->task_id = $task->id;
        $step->save();
    }

    private function startSite(VersionTestStep $step): void
    {
        try {
            $task = ActionFacade::execute(new ProvisionVersionTestSite($step));
        } catch (Throwable $e) {
            report($e);
            $task = null;
        }

        if (! $task) {
            $this->provisioningFailed($step->id, __('messages.exception.version_test_site_failed'), fixable: false);

            return;
        }

        $step->task_id = $task->id;
        $step->save();
    }

    private function clear(VersionTestStep $step, string $outcome): void
    {
        ActionFacade::execute(new ClearVersionTestStep($step, $outcome));
    }

    private function cancelRun(VersionTestRun $run, User $user, string $event): void
    {
        $active = $run->steps()->whereIn('status', VersionTestStepStatus::activeValues())->first();

        // A test org that is still being built can't be pulled out from under its tasks
        $cancellable = [VersionTestStepStatus::AWAITING_CONFIRMATION, VersionTestStepStatus::NEEDS_ATTENTION];
        if ($active && ! in_array($active->status, $cancellable, true)) {
            throw new VersionTestException(__('admin.version_tests.errors.cannot_cancel_now'));
        }

        $run->status = VersionTestRunStatus::CANCELLED;
        $run->finished_at = now();
        $run->save();

        $this->log($run, $event, user: $user);

        if ($active) {
            $this->moveStep($active, $cancellable, VersionTestStepStatus::CLEARING, [
                'failure_reason' => __('admin.version_tests.cancelled_step'),
            ]);
            $this->clear($active, ClearVersionTestStep::FAILED);
        }
    }

    private function finishRun(VersionTestRun $run, VersionTestRunStatus $status, string $event): void
    {
        $run->status = $status;
        $run->finished_at = now();
        $run->save();

        $this->log($run, $event);
        $this->notify(new VersionTestRunFinished($run));
    }

    /**
     * Change a step's status only if it still has one of the expected ones, so
     * a double click or a late task callback can't move it twice.
     *
     * @param  array<int, VersionTestStepStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    private function moveStep(VersionTestStep $step, array $from, VersionTestStepStatus $to, array $attributes = []): bool
    {
        $moved = DB::transaction(function () use ($step, $from, $to, $attributes) {
            $locked = VersionTestStep::lockForUpdate()->find($step->id);

            if (! $locked || ! in_array($locked->status, $from, true)) {
                return false;
            }

            $locked->forceFill([...$attributes, 'status' => $to])->save();

            return true;
        });

        $step->refresh();

        return $moved;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(VersionTestRun $run, string $type, ?VersionTestStep $step = null, ?User $user = null, ?string $message = null, array $context = []): VersionTestEvent
    {
        $event = new VersionTestEvent;
        $event->version_test_run_id = $run->id;
        $event->version_test_step_id = $step?->id;
        $event->user_id = $user?->id;
        $event->type = $type;
        $event->message = $message;
        $event->context = $context ?: null;
        $event->save();

        return $event;
    }

    private function notify($notification): void
    {
        try {
            Organization::where('type', 'superaccount')->first()?->notifyAdmins($notification);
        } catch (Throwable $e) {
            // A notification that can't be sent must not undo the step it is about
            report($e);
        }
    }
}
