<?php

namespace App\Updates\Steps;

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationSSOSetup;
use App\AppInstance;
use App\Support\Facades\Action;
use App\Task;
use App\Updates\UpdateStep;

/** Sets the app up with the plan's single sign-on server */
class SsoStep extends UpdateStep
{
    public function key(): string
    {
        return 'sso';
    }

    public function label(): string
    {
        return __('admin.update_steps.sso.label');
    }

    public function description(): string
    {
        return __('admin.update_steps.sso.description');
    }

    public function appliesTo(AppInstance $app_instance): bool
    {
        return $app_instance->usesOwnResources() && (bool) $app_instance->plan?->sso_server;
    }

    public function afterUpgrade(AppInstance $app_instance): bool
    {
        return true;
    }

    public function handle(Task $task, AppInstance $app_instance): ActionResult
    {
        $setup = Action::execute(new ApplicationSSOSetup($app_instance), $task, true);

        return $setup ? ActionResult::wait()->waitingFor($setup) : ActionResult::done();
    }
}
