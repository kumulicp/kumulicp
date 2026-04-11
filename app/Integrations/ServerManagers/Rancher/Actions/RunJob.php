<?php

namespace App\Integrations\ServerManagers\Rancher\Actions;

use App\Actions\Action;
use App\Integrations\ServerManagers\Rancher\API\Job;
use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;
use App\OrgServer;
use App\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class RunJob extends Action
{
    public $slug = 'run_rancher_job';

    public $action_group = 'rancher';

    public function __construct(private OrgServer $org_server, private JobChart $job_chart, private string $job_name, ?string $namespace = null)
    {
        $this->organization = $org_server->organization;
        $this->job_method = Str::camel($job_name);
        $job_title = str_replace('_', ' ', Str::title($job_name));
        $this->setCustomValues(['job' => serialize($job_chart), 'job_name' => $job_name, 'org_server_id' => $org_server->id, 'namespace' => $namespace]);

        $this->description = $job_title;

        $this->background = 1;
    }

    public static function run(Task $task)
    {
        $org_server = OrgServer::find($task->getValue('org_server_id'));
        $job_name = $task->getValue('job_name');
        $job_chart = unserialize($task->getValue('job'));
        $job_method = Str::camel($task->getValue('job_name'));
        $chart = $job_chart->$job_method();
        $job = new Job($org_server->organization, $org_server);
        if ($namespace = $task->getValue('namespace')) {
            $job->setNamespace($namespace);
        }

        $response = $job->create($chart);

        if ($response) {
            $run_job = new self($org_server, $job_chart, $job_name);
            $run_job->addCustomValue([
                'response' => $response,
                'job_id' => Arr::get($response, 'response.metadata.name'),
                'namespace' => $namespace,
            ]);

            return $run_job;
        }
    }

    public static function retry(Task $task)
    {
        $org_server = OrgServer::find($task->getValue('org_server_id'));
        $retry = new self($org_server, unserialize($task->getValue('job')), $task->getValue('job_name'), $task->getValue('namespace'));
        $retry->status = 'ready';

        return $retry;
    }

    public static function complete(Task $task)
    {
        $org_server = OrgServer::find($task->getValue('org_server_id'));
        $job_id = $task->getValue('job_id');
        $job = new Job($task->organization, $org_server);
        if ($namespace = $task->getValue('namespace')) {
            $job->setNamespace($namespace);
        }
        $job_status = $job->status($job_id);

        if ($job_status == 'success') {
            $task->complete();
            $task->groupNotified();
        }
    }
}
