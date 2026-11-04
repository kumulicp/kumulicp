<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\Actions\ActionResult;
use App\Actions\NativeAction;
use App\Actions\Prerequisites;
use App\AppVersion;
use App\Notifications\TimedAppUpgrade;
use App\Organization;
use App\Support\Facades\Action as ActionFacade;
use App\Task;

class MassApplicationUpgrade extends NativeAction
{
    public $slug = 'mass_application_upgrade';

    public $background = true;

    public function __construct(AppVersion $version)
    {
        $application = $version->application;
        $this->organization = Organization::where('type', 'superaccount')->first();
        $this->application = $version->application;
        $this->version = $version;

        $this->description = __('actions.upgrading_all_apps', ['app' => $application->name, 'version' => $version->name]);

        $prereqs = new Prerequisites;
        $prereqs->add_time_range('12:01 am', '11:59 pm');
        $this->prerequisites = $prereqs->get();
    }

    public static function handle(Task $task): ActionResult
    {
        foreach ($task->application->instancesBehind($task->version) as $app_instance) {
            ActionFacade::execute(new ApplicationUpgrade($app_instance, $task->version));
            $app_instance->organization->notifyAdmins(new TimedAppUpgrade($app_instance));
        }

        return ActionResult::done();
    }

    public static function retry(Task $task): ?Action
    {
        return new self($task->version);
    }
}
