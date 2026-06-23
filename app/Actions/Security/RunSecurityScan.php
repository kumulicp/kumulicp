<?php

namespace App\Actions\Security;

use App\Actions\Action;
use App\Integrations\ServerManagers\Rancher\API\Job;
use App\Integrations\ServerManagers\Rancher\API\Pod;
use App\Integrations\ServerManagers\Rancher\Charts\Job\SecurityScanJobChart;
use App\OrgServer;
use App\SecurityFinding;
use App\SecurityScan;
use App\Support\Security\Parsers\ParserFactory;
use App\Task;
use Illuminate\Support\Arr;

class RunSecurityScan extends Action
{
    public $slug = 'run_scan';

    public $action_group = 'security_scan';

    public $replace = true;

    public function __construct(private OrgServer $org_server, private string $tool, private SecurityScan $security_scan)
    {
        $this->organization = $org_server->organization;
        $this->description = "Security scan ({$tool}) on {$org_server->organization->slug}";
        $this->background = 1;
        $this->setCustomValues([
            'org_server_id' => $org_server->id,
            'security_scan_id' => $security_scan->id,
            'tool' => $tool,
        ]);
    }

    public static function run(Task $task)
    {
        $org_server = OrgServer::find($task->getValue('org_server_id'));
        $tool = $task->getValue('tool');
        $security_scan = SecurityScan::find($task->getValue('security_scan_id'));

        $namespace = $org_server->organization->slug;
        $chart = (new SecurityScanJobChart($tool, $namespace))->run();

        $job = new Job($org_server->organization, $org_server);
        $job->setNamespace($namespace);
        $response = $job->create($chart);

        $run = new self($org_server, $tool, $security_scan);

        if ($response) {
            $security_scan->status = 'running';
            $security_scan->started_at = now();
            $security_scan->save();

            $run->addCustomValue([
                'job_name' => $chart->name,
                'namespace' => $namespace,
            ]);
        } else {
            $security_scan->fail('Failed to create scan job in cluster');
        }

        return $run;
    }

    public static function complete(Task $task)
    {
        $org_server = OrgServer::find($task->getValue('org_server_id'));
        $tool = $task->getValue('tool');
        $security_scan = SecurityScan::find($task->getValue('security_scan_id'));
        $job_name = $task->getValue('job_name');
        $namespace = $task->getValue('namespace');

        $job = new Job($task->organization, $org_server);
        $job->setNamespace($namespace);
        $job_status = $job->status($job_name);

        if ($job_status === 'running') {
            return;
        }

        $pod = new Pod($task->organization, $org_server);
        $pod->setNamespace($namespace);
        $raw_output = $pod->logsForJob($job_name);

        if ($job_status !== 'success' || ! $raw_output) {
            $security_scan->fail("Scan job {$job_status}");
            $task->error_message = "Security scan job {$job_status}";
            $task->status = 'failed';
            $task->save();

            return;
        }

        $security_scan->raw_output = $raw_output;
        $security_scan->save();

        $findings = ParserFactory::make($tool)->parse($raw_output);

        foreach ($findings as $finding) {
            $security_scan->findings()->create([
                'severity' => Arr::get($finding, 'severity', 'info'),
                'title' => Arr::get($finding, 'title', 'Untitled finding'),
                'category' => Arr::get($finding, 'category'),
                'description' => Arr::get($finding, 'description'),
                'remediation' => Arr::get($finding, 'remediation'),
                'rule_id' => Arr::get($finding, 'rule_id'),
            ]);
        }

        $security_scan->summarize();
        $security_scan->complete();

        $task->complete();
        $task->groupNotified();
    }

    public static function retry(Task $task)
    {
        $org_server = OrgServer::find($task->getValue('org_server_id'));
        $security_scan = SecurityScan::find($task->getValue('security_scan_id'));
        $retry = new self($org_server, $task->getValue('tool'), $security_scan);
        $retry->status = 'ready';

        return $retry;
    }
}
