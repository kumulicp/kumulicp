<?php

namespace App\Actions\Tests;

use App\Actions\Action;
use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationDelete;
use App\Actions\NativeAction;
use App\Actions\Organizations\DeleteOrganization;
use App\AppInstance;
use App\Organization;
use App\Services\VersionTesting\VersionTestRunner;
use App\Support\Facades\Action as ActionFacade;
use App\Task;
use App\VersionTestStep;

/**
 * Deletes a version test step's org once its outcome has been decided, and
 * tells the runner when it is gone so the next step can be started.
 */
class ClearVersionTestStep extends NativeAction
{
    public $slug = 'clear_version_test_step';

    public $background = true;

    // The outcome the step gets once its org is gone
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public function __construct(VersionTestStep $step, string $outcome)
    {
        $this->organization = Organization::where('type', 'superaccount')->first();
        $this->description = __('actions.clear_version_test_step', ['plan' => $step->plan->name]);

        $this->setCustomValues(['step_id' => $step->id, 'outcome' => $outcome]);
    }

    public static function handle(Task $task): ActionResult
    {
        $step = VersionTestStep::findOrFail($task->getValue('step_id'));

        // A shared app can't be deleted while a site is in it, so the site goes first
        $site = $step->site_app_instance_id ? AppInstance::find($step->site_app_instance_id) : null;
        if ($site) {
            $deletion = ActionFacade::execute(new ApplicationDelete($site), $task);

            return $deletion ? ActionResult::wait()->waitingFor($deletion) : ActionResult::wait();
        }

        return self::deleteOrganization($task, $step);
    }

    /**
     * Polled once the deletion is complete.
     */
    public static function poll(Task $task): ActionResult
    {
        $step = VersionTestStep::findOrFail($task->getValue('step_id'));

        if ($step->site_app_instance_id && AppInstance::find($step->site_app_instance_id)) {
            return ActionResult::wait();
        }

        if (! $task->getValue('deleting_organization')) {
            return self::deleteOrganization($task, $step);
        }

        return $step->testOrganization() ? ActionResult::wait() : self::finished($task);
    }

    private static function deleteOrganization(Task $task, VersionTestStep $step): ActionResult
    {
        $organization = $step->testOrganization();

        if (! $organization) {
            return self::finished($task);
        }

        $deletion = ActionFacade::execute(new DeleteOrganization($organization), $task);

        $result = ActionResult::wait(['deleting_organization' => true]);

        return $deletion ? $result->waitingFor($deletion) : $result;
    }

    private static function finished(Task $task): ActionResult
    {
        $step_id = (int) $task->getValue('step_id');
        $outcome = (string) $task->getValue('outcome');

        return ActionResult::done()->then(fn (Task $task) => app(VersionTestRunner::class)->cleared($step_id, $outcome));
    }

    public static function retry(Task $task): ?Action
    {
        return new self(VersionTestStep::findOrFail($task->getValue('step_id')), (string) $task->getValue('outcome'));
    }
}
