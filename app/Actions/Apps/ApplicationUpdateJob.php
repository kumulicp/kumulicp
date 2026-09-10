<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Application;
use App\Task;
use Illuminate\Support\Arr;

class ApplicationUpdateJob extends Action
{
    public $slug = 'application_update_job';

    public static bool $long_running = true;

    public function __construct(AppInstance $app_instance, string $job)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->setCustomValues(['job_name' => $job]);
        $job_title = ucwords(str_replace('_', ' ', $job));

        $this->description = "$job_title: {$app_instance->label}";

        // Multiple children of the same shared-app hub can have their own
        // site-provisioning jobs dispatched around the same time -- queue
        // behind any job already in flight against the same hub instead of
        // firing a second Kubernetes Job concurrently against the same
        // release/bench. A failed sibling still blocks (not just complete):
        // it needs to be investigated and resolved before any other
        // create/migrate/drop-site job against this hub should proceed.
        $hub = $app_instance->sharedPlanParent() ?? $app_instance;
        $sibling_instance_ids = $hub->children()->pluck('id')->push($hub->id);

        $existing_task = Task::where('action_slug', $this->slug)
            ->whereIn('app_instance_id', $sibling_instance_ids)
            ->where('app_instance_id', '!=', $app_instance->id)
            ->where('status', '!=', 'complete')
            ->latest('id')
            ->first();

        if ($existing_task) {
            $this->prerequisites = (new Prerequisites)->add_waiting_for($existing_task)->get();
        }
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
            $status = $server->jobStatus($task->getValue('job_id'));

            if ($status == 'success') {

                // Copmletes parent task faster
                if ($parent_task_id = $task->getValue('parent_task_id')) {
                    $parent_task = ActionFacade::complete(Task::find($parent_task_id));
                }
                $task->complete();
                $task->groupNotified();
            } elseif ($status == 'failed') {
                $task->error_message = __('messages.api.rancher.error.job', ['job' => $task->getValue('job_id'), 'message' => '']);
                $task->status = 'failed';
                $task->save();
            }
        }
    }
}
