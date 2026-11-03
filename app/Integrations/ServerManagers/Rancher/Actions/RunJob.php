<?php

namespace App\Integrations\ServerManagers\Rancher\Actions;

use App\Actions\Action;
use App\Actions\ActionResult;
use App\Actions\NativeAction;
use App\Enums\TaskStatus;
use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;
use App\OrgServer;
use App\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class RunJob extends NativeAction
{
    public $slug = 'run_rancher_job';

    public $action_group = 'rancher';

    public function __construct(private OrgServer $org_server, private JobChart $job_chart, private string $job_name, ?string $namespace = null)
    {
        $this->organization = $org_server->organization;
        $job_title = ucwords(str_replace('_', ' ', $job_name));
        $this->setCustomValues(['job' => serialize($job_chart), 'job_name' => $job_name, 'org_server_id' => $org_server->id, 'namespace' => $namespace]);

        $this->description = $job_title;

        $this->background = 1;
    }

    public static function handle(Task $task): ActionResult
    {
        $org_server = OrgServer::find($task->getValue('org_server_id'));
        $job_name = $task->getValue('job_name');
        $job_chart = unserialize($task->getValue('job'));
        $job_method = Str::camel($task->getValue('job_name'));
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
            ]), 'job_create_failed');
        }

        return ActionResult::wait([
            'response' => $response,
            'job_id' => Arr::get($response, 'response.metadata.name'),
            'namespace' => $namespace,
        ]);
    }

    public static function retry(Task $task): ?Action
    {
        $org_server = OrgServer::find($task->getValue('org_server_id'));
        $retry = new self($org_server, unserialize($task->getValue('job')), $task->getValue('job_name'), $task->getValue('namespace'));
        $retry->status = TaskStatus::READY;

        return $retry;
    }

    public static function poll(Task $task): ActionResult
    {
        $org_server = OrgServer::find($task->getValue('org_server_id'));
        $job_id = $task->getValue('job_id');

        return $org_server->connect()->jobStatus($job_id, $task->getValue('namespace')) == 'success' ? ActionResult::done() : ActionResult::wait();
    }
}
