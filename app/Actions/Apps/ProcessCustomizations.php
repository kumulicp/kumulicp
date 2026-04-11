<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\Support\Facades\Application;

class ProcessCustomizations extends Action
{
    public $slug = 'process_customizations';

    public $background = true;

    public function __construct(AppInstance $app_instance, $custom_values = null)
    {
        $prereqs = new Prerequisites;
        $prereqs->add_application_required($app_instance);
        $this->prerequisites = $prereqs->get();

        $this->description = __('actions.updating_app_customizations', ['app' => $app_instance->label]);

        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->setCustomValues($custom_values);
    }

    public static function run($task)
    {
        $app_instance = Application::instance($task->app_instance);
        $app_instance->features()->update($task->customValues());
        $app_instance_service = $app_instance->updateCustomizations($task->customValues());

        $task->complete();
    }
}
