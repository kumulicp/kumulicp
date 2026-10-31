<?php

namespace App\Actions\Tests;

use App\Actions\Action;
use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationActivate;
use App\Actions\Apps\ApplicationUpgrade;
use App\Actions\NativeAction;
use App\AppInstance;
use App\Enums\VersionTestStepType;
use App\Exceptions\Tasks\PermanentFailure;
use App\Organization;
use App\Plan;
use App\Services\VersionTesting\VersionTestRunner;
use App\Support\Facades\Action as ActionFacade;
use App\Task;
use App\VersionTestStep;

/**
 * Builds the test org for one version test step and gets its app into the state
 * to be tried out: freshly installed, or installed on the enabled version and
 * then upgraded. Marks the step ready for the admin when that is done.
 */
class ProvisionVersionTestStep extends NativeAction
{
    public $slug = 'provision_version_test_step';

    public $background = true;

    public static bool $long_running = true;

    public ?int $deadline_minutes = 120;

    public function __construct(VersionTestStep $step)
    {
        $this->organization = Organization::where('type', 'superaccount')->first();
        $this->description = __('actions.provision_version_test_step', [
            'app' => $step->run->application->name,
            'version' => $step->run->version->name,
            'plan' => $step->plan->name,
        ]);

        $this->setCustomValues(['step_id' => $step->id]);
    }

    public static function handle(Task $task): ActionResult
    {
        $step = VersionTestStep::findOrFail($task->getValue('step_id'));
        $run = $step->run;
        $application = $run->application;

        $install_version = $step->type === VersionTestStepType::UPGRADE ? $step->from_version : $run->version;

        $base_plan = Plan::where('is_default', 1)->first();
        if (! $base_plan) {
            throw new PermanentFailure(__('messages.exception.no_default_plan'));
        }

        // A retried task finds the org it already made
        $organization = $step->testOrganization();
        if (! $organization) {
            $number = CreateTestOrganization::lastNumber() + 1;
            [$organization] = (new CreateTestOrganization)->create($step->account_test, $base_plan, $number, (string) $step->test_password);

            $step->test_username = $organization->slug;
            $step->save();
        }

        $activate = ActionFacade::execute(new ApplicationActivate(
            organization: $organization,
            app: $application,
            plan: $step->plan,
            version: $install_version,
        ), $task);

        if (! $activate) {
            throw new PermanentFailure(__('messages.exception.version_test_activate_failed'));
        }

        $step->app_instance_id = $activate->app_instance_id;
        $step->save();

        return ActionResult::wait(['phase' => 'activating'])->waitingFor($activate);
    }

    /**
     * Polled once the tasks it waited for are complete: the app is installed,
     * and for an upgrade step is yet to be upgraded.
     */
    public static function poll(Task $task): ActionResult
    {
        $step = VersionTestStep::findOrFail($task->getValue('step_id'));

        if ($step->type === VersionTestStepType::UPGRADE && $task->getValue('phase') === 'activating') {
            $app_instance = AppInstance::findOrFail($step->app_instance_id);

            // The upgrade is not announced to anyone: nobody but the tester runs this app
            $upgrade = ActionFacade::execute(new ApplicationUpgrade($app_instance, $step->run->version, null, false), $task);

            if (! $upgrade) {
                return ActionResult::fail(__('messages.exception.version_test_upgrade_failed'));
            }

            return ActionResult::wait(['phase' => 'upgrading'])->waitingFor($upgrade);
        }

        return ActionResult::done()->then(fn (Task $task) => app(VersionTestRunner::class)->provisioned($step->id));
    }

    public static function compensate(Task $task): void
    {
        app(VersionTestRunner::class)->provisioningFailed(
            (int) $task->getValue('step_id'),
            $task->error_message ?: __('messages.exception.version_test_provisioning_failed'),
        );
    }

    public static function retry(Task $task): ?Action
    {
        return new self(VersionTestStep::findOrFail($task->getValue('step_id')));
    }
}
