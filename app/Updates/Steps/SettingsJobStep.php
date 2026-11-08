<?php

namespace App\Updates\Steps;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationUpdateJob;
use App\AppInstance;
use App\Support\Facades\Action;
use App\Task;
use App\Updates\UpdateStep;

/** Runs the app's update_settings job, if its profile has one */
class SettingsJobStep extends UpdateStep
{
    public function key(): string
    {
        return 'settings-job';
    }

    public function label(): string
    {
        return __('admin.update_steps.settings_job.label');
    }

    public function description(): string
    {
        return __('admin.update_steps.settings_job.description');
    }

    public function appliesTo(AppInstance $app_instance): bool
    {
        return $app_instance->usesOwnResources();
    }

    public function checkedByDefault(): bool
    {
        return true;
    }

    public function handle(Task $task, AppInstance $app_instance): ActionResult
    {
        $job = Action::execute(new ApplicationUpdateJob($app_instance, 'update_settings'), $task, true);

        return $job ? ActionResult::wait()->waitingFor($job) : ActionResult::done();
    }
}
