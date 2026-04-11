<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\AppInstance;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Application;
use App\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ApplicationUpdateJob extends Action
{
    public $slug = 'application_update_job';

    public function __construct(AppInstance $app_instance, string $job)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->setCustomValues(['job_name' => $job]);
        $job_title = str_replace('_', ' ', Str::title($job));

        $this->description = "$job_title: {$app_instance->label}";
    }

    public static function run(Task $task)
    {
        $job = Application::runJob($task->app_instance, $task->getValue('job_name'));

        // If no job returned, do nothing
        if (! $job) {
            $task->delete();

            return;
        }
        $action = new self($task->app_instance, $task->getValue('job_name'));
        $action->addCustomValue(['job_id' => Arr::get($job, 'response.metadata.name')]);

        return $action;
    }

    public static function retry(Task $task)
    {
        $task->status = 'ready';
        $task->save();

        return new self($task->app_instance, $task->getValue('job_name'));
    }

    public static function complete(Task &$task)
    {
        $app_instance = Application::instance($task->app_instance);
        if ($parent_app = $app_instance->parent) {
            $app_instance = Application::instance($parent_app);
        }

        if ($app_instance && $server = $app_instance->connect('web')) {
            if ($server->jobStatus($task->getValue('job_id')) == 'success') {

                // Copmletes parent task faster
                if ($parent_task_id = $task->getValue('parent_task_id')) {
                    $parent_task = ActionFacade::complete(Task::find($parent_task_id));
                }
                $task->complete();
                $task->groupNotified();
            }
        }
    }
}
