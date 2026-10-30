<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\Actions\ActionResult;
use App\Actions\NativeAction;
use App\AppInstance;
use App\Services\Tasks\TaskDispatcher;
use App\Support\Facades\Application;
use App\Task;

class ApplicationSSOSetup extends NativeAction
{
    public $slug = 'application_sso_setup';

    public function __construct(AppInstance $app_instance)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;

        $this->description = __('actions.sso_setup', ['app' => $app_instance->name]);
    }

    /**
     * A SSONotReadyException from the SSO server is a WaitingException: the
     * runner puts the task back to pending and tries again shortly.
     */
    public static function handle(Task $task): ActionResult
    {
        $app_instance = Application::instance($task->app_instance);
        $sso = $app_instance->connect('sso');

        if (! $sso) {
            throw new \Exception(__('messages.exception.sso_failed'));
        }

        if ($app_instance->setting('sso') && $sso->exists()) {
            $sso->update();
        } else {
            $sso->add();
        }

        // The task that was waiting on this one can go now that it is complete
        return ActionResult::done()->then(function (Task $task) {
            if (($parent_task_id = $task->getValue('parent_task_id')) && $parent_task = Task::find($parent_task_id)) {
                app(TaskDispatcher::class)->queue($parent_task);
            }
        });
    }

    public static function retry(Task $task): ?Action
    {
        $action = new self($task->app_instance);
        $action->setCustomValues($task->custom_values);

        return $action;
    }
}
