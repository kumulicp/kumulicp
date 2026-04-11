<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\AppInstance;
use App\Events\Apps\AppInstanceUpdated;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Application;
use App\Task;

class ApplicationUpdate extends Action
{
    public $slug = 'application_update';

    public function __construct(AppInstance $app_instance)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->app_instance->status = 'updating';
        $this->app_instance->save();

        $this->description = __('actions.updating_app', ['app' => $app_instance->application->name]);
    }

    public static function run(Task $task)
    {
        $app_instance = Application::instance($task->app_instance);
        $app_update = new self($app_instance->app_instance);
        $server = $app_instance->connect('web');

        if ($job = ActionFacade::execute(new ApplicationUpdateJob($app_instance->app_instance, 'update_settings'), $task, true)) {
            $app_update->addCustomValue(['waiting_for' => [$job->id]]);
        }

        $server->update();

        return $app_update;
    }

    public static function retry(Task $task)
    {
        return new self($task->app_instance);
    }

    public static function complete(Task &$task)
    {
        $app_instance = Application::instance($task->app_instance);
        $child_app = null;

        if ($parent_app = $app_instance->parent) {
            $child_app = $app_instance;
            $app_instance = Application::instance($parent_app);
        }

        if ($app_instance && $server = $app_instance->connect('web')) {

            if ($server->isActive()) {

                $app_instance->status = 'active';
                $app_instance->save();

                // If main app being updated is a child app, also change status back to active
                if ($child_app) {
                    $child_app->status = 'active';
                    $child_app->save();
                }

                $task->complete();
                $task->groupNotified();

                event(new AppInstanceUpdated($app_instance));
            }
        }
    }
}
