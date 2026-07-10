<?php

namespace App\Integrations\ServerManagers\Rancher\Charts\Job;

use App\Support\Facades\SecurityTool;
use Illuminate\Support\Str;

class SecurityScanJobChart extends JobChart
{
    public $chart = [];

    public function __construct(public string $tool, public string $namespace_name)
    {
        $this->name = "security-scan-{$tool}-".Str::lower(Str::random(8));
        $this->namespace = $this->namespace_name;
    }

    public function run(?string $job_name = null)
    {
        if (! $job_name) {
            $job_name = $this->name;
        }

        $profile = SecurityTool::profile($this->tool);

        if (! $profile) {
            throw new \Exception(__('messages.exception.no_security_tool', ['tool' => $this->tool]));
        }

        $this->chart = [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => [
                'name' => $job_name,
                'namespace' => $this->namespace_name,
                'labels' => [
                    'app.kubernetes.io/managed-by' => 'kumulicp-security-scan',
                    'kumulicp.io/tool' => $this->tool,
                ],
            ],
            'spec' => [
                'backoffLimit' => 0,
                'completions' => 1,
                'parallelism' => 1,
                'ttlSecondsAfterFinished' => 3600,
                'template' => [
                    'spec' => [
                        'containers' => [[
                            'name' => 'security-scan',
                            'image' => $profile->image(),
                            'command' => $profile->command(),
                            'imagePullPolicy' => 'Always',
                            'terminationMessagePath' => '/dev/termination-log',
                            'terminationMessagePolicy' => 'File',
                        ]],
                        'dnsPolicy' => 'ClusterFirst',
                        'restartPolicy' => 'Never',
                        'serviceAccountName' => 'default',
                        'terminationGracePeriodSeconds' => 30,
                    ],
                ],
            ],
        ];

        return $this;
    }
}
