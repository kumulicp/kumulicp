<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\AppInstance;
use App\Enums\TaskStatus;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\Application;
use App\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

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
            ->whereIn('status', TaskStatus::openValues())
            ->latest('id')
            ->first();

        if ($existing_task) {
            $this->dependsOn($existing_task);
        }
    }

    public static function run(Task $task)
    {
        $job = Application::runJob($task->app_instance, $task->getValue('job_name'));

        // No job class registered for this app/action.
        if (! $job) {
            $job_name = $task->getValue('job_name');

            // An app that runs its lifecycle as jobs has to have the job, or the
            // activation, upgrade or delete would report success without doing
            // anything (e.g. a plugin that is not installed, or an old version
            // of it). The other jobs (update_settings, update_domain, ...) are
            // optional for an app, so nothing to run is fine for them.
            if (self::lifecycleJobMissing($task->app_instance, $job_name)) {
                $task->error_message = __('messages.exception.job_not_registered', [
                    'job' => $job_name,
                    'app' => $task->app_instance->application->name,
                ]);
                $task->status = TaskStatus::FAILED;
                $task->save();

                Log::warning('Job not registered for an app that runs it as a job', ['app_instance_id' => $task->app_instance_id, 'job' => $job_name]);

                return;
            }

            // Gone counts as done: whatever was waiting for it is told now
            $runner = app(TaskRunner::class);
            $waiting = $runner->dependentsOf([$task]);

            $task->delete();
            $runner->wake($waiting);

            return;
        }

        // Job/JobChart::create() (both the Rancher and Kubernetes
        // drivers) return this shape without throwing even when the
        // create/apply itself failed -- has to be checked explicitly, or a
        // failed creation silently proceeds with a null job_id.
        if (Arr::get($job, 'status') !== 'success') {
            $task->error_message = __('messages.api.rancher.error.job', [
                'job' => $task->getValue('job_name'),
                'message' => is_string($job['response'] ?? null) ? $job['response'] : json_encode($job['response'] ?? null),
            ]);
            $task->status = TaskStatus::FAILED;
            $task->save();

            return;
        }

        $action = new self($task->app_instance, $task->getValue('job_name'));
        $action->addCustomValue(['job_id' => Arr::get($job, 'response.metadata.name')]);

        return $action;
    }

    // The jobs an app with the 'job' activation type cannot do without
    private const LIFECYCLE_JOBS = ['activate', 'upgrade', 'deactivate'];

    private static function lifecycleJobMissing(AppInstance $app_instance, string $job_name): bool
    {
        return in_array($job_name, self::LIFECYCLE_JOBS, true)
            && Application::profile($app_instance->application->slug)->activationType($app_instance) === 'job';
    }

    public static function retry(Task $task)
    {
        $task->status = TaskStatus::READY;
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

                $task->complete();
                $task->groupNotified();
            } elseif ($status == 'failed') {
                $task->error_message = __('messages.api.rancher.error.job', ['job' => $task->getValue('job_id'), 'message' => '']);
                $task->status = TaskStatus::FAILED;
                $task->save();
            }
        }
    }
}
