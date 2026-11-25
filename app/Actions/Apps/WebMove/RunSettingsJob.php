<?php

namespace App\Actions\Apps\WebMove;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationUpdateJob;
use App\Actions\Apps\ApplicationWebMove;
use App\Actions\Steps\Step;
use App\Enums\TaskStatus;
use App\Support\Facades\Action as ActionFacade;
use App\Task;

/**
 * Applies the app's settings on the new server, with the app's update_settings job, once the release is
 * known to be running there (Verify). The job runs the app's own commands against it, so it has to
 * wait for the install: started with it (as the standard update does) it runs against a release that is
 * not there yet, or never installed.
 *
 * An app with no such job has nothing to do. A job that fails rolls the move back, before the DNS records
 * are changed. There is nothing of its own to undo: the settings were applied to the new release, which the
 * move leaves, and the old one was not touched.
 */
class RunSettingsJob extends Step
{
    public function run(Task $task): ActionResult
    {
        $app_instance = $task->app_instance;

        // One from before would have been for the app as it was
        ActionFacade::clear('application_update_job', $app_instance);
        $job = ActionFacade::execute(new ApplicationUpdateJob($app_instance, 'update_settings'), $task, true);

        if (! $job) {
            return ActionResult::fail(__('messages.web_move.settings_not_scheduled'));
        }

        return ActionResult::wait(['settings_job_task_id' => $job->id]);
    }

    public function check(Task $task): ActionResult
    {
        $job = Task::find($task->getValue('settings_job_task_id'));

        // An app with no such job: it is removed as done
        if (! $job || $job->status === TaskStatus::COMPLETE) {
            return ActionResult::done();
        }

        if ($job->status === TaskStatus::FAILED) {
            return ActionResult::fail($this->failure($task));
        }

        return ActionResult::wait();
    }

    public function timeoutMinutes(): ?int
    {
        return ApplicationWebMove::SETTINGS_TIMEOUT_MINUTES;
    }

    public function timeoutMessage(Task $task): string
    {
        return $this->failure($task);
    }

    private function failure(Task $task): string
    {
        return Task::find($task->getValue('settings_job_task_id'))?->error_message ?: __('messages.web_move.settings_failed');
    }
}
