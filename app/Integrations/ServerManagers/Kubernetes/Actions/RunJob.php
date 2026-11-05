<?php

namespace App\Integrations\ServerManagers\Kubernetes\Actions;

use App\Actions\ActionResult;
use App\Actions\NativeAction;
use App\Enums\TaskErrorCode;
use App\Integrations\ServerManagers\Kubernetes\Charts\Job\JobChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\Job\RebuildableJobChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\Job\JobChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\Job\RebuildableJobChart;
use App\OrgServer;
use App\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Runs a one-off Job through the server's own driver (Kubernetes or Rancher).
 *
 * The slug and group keep their original "rancher" names: they are stored on
 * existing tasks and looked up by name (see the DKIM controller), so renaming
 * them would orphan those rows.
 */
class RunJob extends NativeAction
{
    public $slug = 'run_rancher_job';

    public $action_group = 'rancher';

    public function __construct(private OrgServer $org_server, private JobChart $job_chart, private string $job_name, ?string $namespace = null)
    {
        if (! $job_chart instanceof RebuildableJobChart) {
            throw new \InvalidArgumentException($job_chart::class.' must implement '.RebuildableJobChart::class.' to be run as a task');
        }

        $this->organization = $org_server->organization;
        $job_title = ucwords(str_replace('_', ' ', $job_name));
        // The chart is stored as the class and the ids it is built from, not as the object
        $this->setCustomValues([
            'chart' => $job_chart::class,
            'chart_args' => $job_chart->taskValues(),
            'job_name' => $job_name,
            'org_server_id' => $org_server->id,
            'namespace' => $namespace,
        ]);

        $this->description = $job_title;

        $this->background = 1;
    }

    public static function handle(Task $task): ActionResult
    {
        $job_name = $task->getValue('job_name');

        if (! $job_chart = self::chart($task)) {
            return ActionResult::fail(__('messages.api.rancher.error.job_unavailable', ['job' => $job_name]), TaskErrorCode::JOB_UNAVAILABLE);
        }

        $org_server = OrgServer::find($task->getValue('org_server_id'));
        $job_method = Str::camel($job_name);
        $chart = $job_chart->$job_method();
        $namespace = $task->getValue('namespace');

        // The server's own driver runs the Job. It returns a result either way
        // (it does not throw when the cluster refuses the Job), so success has
        // to be checked explicitly.
        $response = $org_server->connect()->runJob($chart, $namespace);

        if (Arr::get($response, 'status') !== 'success') {
            $message = $response['response'] ?? '';

            return ActionResult::fail(__('messages.api.rancher.error.job', [
                'job' => $job_name,
                'message' => is_string($message) ? $message : json_encode($message),
            ]), TaskErrorCode::JOB_CREATE_FAILED);
        }

        return ActionResult::wait([
            'response' => $response,
            'job_id' => Arr::get($response, 'response.metadata.name'),
            'namespace' => $namespace,
        ]);
    }

    public static function reset(Task $task): void
    {
        // A task made before charts were stored this way keeps its values and
        // fails clearly when it runs. Otherwise only what it was made with stays:
        // not the previous run's job.
        if ($task->getValue('chart')) {
            $task->custom_values = Arr::only($task->customValues(), ['chart', 'chart_args', 'job_name', 'org_server_id', 'namespace']);
        }
    }

    /**
     * The chart the task was made with, built again from what it stored. Only a
     * class that says it can be rebuilt is ever built from the database.
     */
    public static function chart(Task $task): ?JobChart
    {
        $class = $task->getValue('chart');

        if (! is_string($class) || ! is_subclass_of($class, RebuildableJobChart::class) || ! is_subclass_of($class, JobChart::class)) {
            return null;
        }

        return $class::fromTaskValues($task->getValue('chart_args') ?? []);
    }

    public static function poll(Task $task): ActionResult
    {
        $org_server = OrgServer::find($task->getValue('org_server_id'));
        $job_id = $task->getValue('job_id');

        return $org_server->connect()->jobStatus($job_id, $task->getValue('namespace')) == 'success' ? ActionResult::done() : ActionResult::wait();
    }
}
