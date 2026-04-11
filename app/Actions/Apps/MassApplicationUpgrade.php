<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\AppVersion;
use App\Notifications\TimedAppUpgrade;
use App\Organization;
use App\Support\Facades\Action as ActionFacade;
use App\Task;

class MassApplicationUpgrade extends Action
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

    public static function run($task)
    {
        $prereqs = new Prerequisites;
        $prereqs->add_time_range('12:01 am', '11:59 pm');
        $prereqs->prerequisites = $prereqs->get();

        $organizations_with_version = AppInstance::where('application_id', $task->application_id)->get();

        foreach ($organizations_with_version as $app_instance) {
            if ($app_instance->organization && $app_instance->version->name < $task->version->name) {
                ActionFacade::execute(new ApplicationUpgrade($app_instance, $task->version));
                $app_instance->organization->notifyAdmins(new TimedAppUpgrade($app_instance));
            }
        }

        $task->complete();
    }

    public static function retry(Task $task)
    {
        return new self($task->version);
    }
}
