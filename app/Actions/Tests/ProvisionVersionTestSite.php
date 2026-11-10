<?php

namespace App\Actions\Tests;

use App\Actions\Action;
use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationActivate;
use App\Actions\NativeAction;
use App\AppInstance;
use App\AppPlan;
use App\Exceptions\Tasks\PermanentFailure;
use App\Organization;
use App\Services\VersionTesting\VersionTestRunner;
use App\Support\Facades\Action as ActionFacade;
use App\Task;
use App\VersionTestStep;

/**
 * Adds a site to the shared app a version test step installed, as another
 * organization would, so there is something to log in to. The shared app on
 * its own is only a container for sites. Marks the step ready for the admin
 * when that is done.
 */
class ProvisionVersionTestSite extends NativeAction
{
    public $slug = 'provision_version_test_site';

    public $background = true;

    public static bool $long_running = true;

    public ?int $deadline_minutes = 120;

    public function __construct(VersionTestStep $step)
    {
        $this->organization = Organization::where('type', 'superaccount')->first();
        $this->description = __('actions.provision_version_test_site', [
            'app' => $step->run->application->name,
            'version' => $step->run->version->name,
            'plan' => $step->plan->name,
        ]);

        $this->setCustomValues(['step_id' => $step->id]);
    }

    public static function handle(Task $task): ActionResult
    {
        $step = VersionTestStep::findOrFail($task->getValue('step_id'));
        $organization = $step->testOrganization();
        $shared_app = $step->app_instance_id ? AppInstance::find($step->app_instance_id) : null;

        if (! $organization || ! $shared_app) {
            throw new PermanentFailure(__('messages.exception.version_test_site_failed'));
        }

        // A retried task has already made its plan and site
        if ($step->site_app_instance_id) {
            return ActionResult::wait(['phase' => 'activating']);
        }

        $plan = self::sitePlan($step, $shared_app);

        $activate = ActionFacade::execute(new ApplicationActivate(
            organization: $organization,
            app: $step->run->application,
            plan: $plan,
            version: $step->run->version,
        ), $task);

        if (! $activate) {
            throw new PermanentFailure(__('messages.exception.version_test_site_failed'));
        }

        $step->site_app_instance_id = $activate->app_instance_id;
        $step->save();

        return ActionResult::wait(['phase' => 'activating'])->waitingFor($activate);
    }

    /**
     * Polled once the tasks it waited for are complete: the site is added.
     */
    public static function poll(Task $task): ActionResult
    {
        $step = VersionTestStep::findOrFail($task->getValue('step_id'));

        // A retry that found its site already made gets here before the site is up
        if ($step->site_app_instance_id && AppInstance::find($step->site_app_instance_id)?->status !== 'active') {
            return ActionResult::wait();
        }

        $step_id = $step->id;

        return ActionResult::done()->then(fn (Task $task) => app(VersionTestRunner::class)->siteProvisioned($step_id));
    }

    public static function compensate(Task $task): void
    {
        app(VersionTestRunner::class)->provisioningFailed(
            (int) $task->getValue('step_id'),
            $task->error_message ?: __('messages.exception.version_test_site_failed'),
        );
    }

    public static function retry(Task $task): ?Action
    {
        return new self(VersionTestStep::findOrFail($task->getValue('step_id')));
    }

    /**
     * The plan a site of the shared app is added with. A shared app's own plan
     * is not one anybody else can pick, and the plans that sites are added with
     * point at the real shared app, so a throwaway one is made for the test
     * release. The runner deletes it with the test org.
     */
    private static function sitePlan(VersionTestStep $step, AppInstance $shared_app): AppPlan
    {
        $plan = $step->site_plan_id ? AppPlan::find($step->site_plan_id) : null;

        if ($plan) {
            return $plan;
        }

        $plan = $step->plan->replicate();
        $plan->name = $step->plan->name.' (test site)';
        $plan->setAttribute('hidden', true);
        $plan->shared_app_id = $shared_app->id;
        $plan->updateSettings(['server_type' => 'shared']);
        $plan->save();

        $step->site_plan_id = $plan->id;
        $step->save();

        return $plan;
    }
}
