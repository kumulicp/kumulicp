<?php

namespace App\Services\VersionTesting;

use App\AccountTest;
use App\Actions\Tests\ClearVersionTestStep;
use App\Actions\Tests\ProvisionVersionTestStep;
use App\AppInstance;
use App\AppPlan;
use App\AppVersion;
use App\Enums\TaskStatus;
use App\Enums\VersionTestRunStatus;
use App\Enums\VersionTestStepStatus;
use App\Enums\VersionTestStepType;
use App\Exceptions\VersionTestException;
use App\Notifications\VersionTestReady;
use App\Notifications\VersionTestRunFinished;
use App\Notifications\VersionTestStepFailed;
use App\Organization;
use App\Plan;
use App\Support\Facades\Action as ActionFacade;
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
     * @throws VersionTestException
     */
    public function start(AppVersion $version, User $user): VersionTestRun
    {
        $plans = AppPlan::where('application_id', $version->application_id)
            ->where('archive', 0)
            ->visible()
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        if ($plans->isEmpty()) {
            throw new VersionTestException(__('admin.version_tests.errors.no_plans'));
        }

        $run = DB::transaction(function () use ($version, $user, $plans) {
            $this->assertSlotFree();

            $baseline = $version->application->active_version();
            if ($baseline && $baseline->is($version)) {
                $baseline = null;
            }

            $run = new VersionTestRun;
            $run->application_id = $version->application_id;
            $run->app_version_id = $version->id;
            $run->baseline_version_id = $baseline?->id;
            $run->status = VersionTestRunStatus::RUNNING;
            $run->fingerprint = (new VersionFingerprint)->for($version);
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

            if (! $baseline) {
                $this->log($run, 'no_baseline', user: $user);
            }

            return $run;
        });

        $this->startStep($run->steps()->first(), $user);

        return $run->refresh();
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

            $this->assertSlotFree();

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
     */
    public function confirmWorking(VersionTestStep $step, User $user): void
    {
        if (! $this->moveStep($step, [VersionTestStepStatus::AWAITING_CONFIRMATION], VersionTestStepStatus::CLEARING, [
            'confirmed_at' => now(),
            'confirmed_by_id' => $user->id,
        ])) {
            throw new VersionTestException(__('admin.version_tests.errors.not_awaiting'));
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
     * @throws VersionTestException
     */
    public function bypass(AppVersion $version, User $user, string $reason): VersionTestRun
    {
        $running = $version->testRuns()->where('status', VersionTestRunStatus::RUNNING->value)->first();
        if ($running) {
            $this->cancelRun($running, $user, 'cancelled_by_bypass');
        }

        $run = new VersionTestRun;
        $run->application_id = $version->application_id;
        $run->app_version_id = $version->id;
        $run->status = VersionTestRunStatus::BYPASSED;
        $run->fingerprint = (new VersionFingerprint)->for($version);
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
        $domain = $app_instance?->domain();

        if (! $this->moveStep($step, [VersionTestStepStatus::PROVISIONING], VersionTestStepStatus::AWAITING_CONFIRMATION, [
            'ready_at' => now(),
            'app_url' => $domain ? 'https://'.$domain : null,
        ])) {
            return;
        }

        $this->log($step->run, 'ready_for_testing', $step);
        $this->notify(new VersionTestReady($step->refresh()));
    }

    /**
     * Provisioning a step's org failed. It is cleared and the run fails.
     */
    public function provisioningFailed(int $step_id, string $reason): void
    {
        $step = VersionTestStep::find($step_id);
        if (! $step) {
            return;
        }

        if (! $this->moveStep($step, [VersionTestStepStatus::PROVISIONING], VersionTestStepStatus::CLEARING, ['failure_reason' => $reason])) {
            return;
        }

        $this->log($step->run, 'provisioning_failed', $step, message: $reason);
        $this->notify(new VersionTestStepFailed($step->refresh()));
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
     * one. A blocked task isn't run again, so nothing else would notice.
     */
    public function syncProvisioning(): void
    {
        $steps = VersionTestStep::where('status', VersionTestStepStatus::PROVISIONING->value)->get();

        foreach ($steps as $step) {
            $task = $step->task_id ? Task::find($step->task_id) : null;

            if ($task && ! in_array($task->status, [TaskStatus::FAILED, TaskStatus::BLOCKED], true)) {
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

    private function assertSlotFree(): void
    {
        $running = VersionTestRun::where('status', VersionTestRunStatus::RUNNING->value)->lockForUpdate()->first();
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
        $account_test->settings = ['base_plan' => Plan::where('is_default', 1)->value('id'), 'apps' => []];
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
            $this->provisioningFailed($step->id, __('messages.exception.version_test_provisioning_failed'));

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
        if ($active && $active->status !== VersionTestStepStatus::AWAITING_CONFIRMATION) {
            throw new VersionTestException(__('admin.version_tests.errors.cannot_cancel_now'));
        }

        $run->status = VersionTestRunStatus::CANCELLED;
        $run->finished_at = now();
        $run->save();

        $this->log($run, $event, user: $user);

        if ($active) {
            $this->moveStep($active, [VersionTestStepStatus::AWAITING_CONFIRMATION], VersionTestStepStatus::CLEARING, [
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
