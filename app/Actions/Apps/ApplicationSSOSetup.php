<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\AppInstance;
use App\Exceptions\SSONotReadyException;
use App\Jobs\RunAction;
use App\Support\Facades\Application;
use App\Task;

class ApplicationSSOSetup extends Action
{
    public $slug = 'application_sso_setup';

    public function __construct(AppInstance $app_instance)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;

        $this->description = __('actions.sso_setup', ['app' => $app_instance->name]);
    }

    public static function run(Task $task)
    {
        $app_instance = Application::instance($task->app_instance);
        $sso = $app_instance->connect('sso');

        if ($sso) {
            try {
                if ($app_instance->setting('sso') && $sso->exists()) {
                    $response = $sso->update();
                } else {
                    $response = $sso->add();
                }
            } catch (SSONotReadyException $e) {
                $task->status = 'pending';
                $task->error_message = $e->getMessage();
                $task->save();

                return new self($task->app_instance);
            }
        } else {
            throw new \Exception(__('messages.exception.sso_failed'));
        }

        $task->complete();
        $task->groupNotified();

        if ($parent_task_id = $task->getValue('parent_task_id')) {
            $parent_task = RunAction::dispatch(Task::find($parent_task_id));
        }

        return new self($task->app_instance);
    }

    public static function retry(Task $task)
    {
        $action = new self($task->app_instance);
        $action->setCustomValues($task->custom_values);

        return $action;
    }

    public static function complete(Task $task) {}
}
