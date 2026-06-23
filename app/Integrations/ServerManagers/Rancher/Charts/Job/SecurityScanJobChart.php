<?php

namespace App\Integrations\ServerManagers\Rancher\Charts\Job;

use Illuminate\Support\Str;

class SecurityScanJobChart extends JobChart
{
    public $chart = [];

    public const IMAGES = [
        'kube-hunter' => 'aquasec/kube-hunter:latest',
        'kube-bench' => 'aquasec/kube-bench:latest',
        'kubescape' => 'quay.io/kubescape/kubescape:latest',
        'trivy' => 'aquasec/trivy:latest',
        'polaris' => 'quay.io/fairwinds/polaris:latest',
        'nuclei' => 'projectdiscovery/nuclei:latest',
    ];

    public const COMMANDS = [
        'kube-hunter' => ['kube-hunter', '--pod', '--report', 'json'],
        'kube-bench' => ['kube-bench', 'run', '--json'],
        'kubescape' => ['kubescape', 'scan', '--format', 'json'],
        'trivy' => ['trivy', 'k8s', '--report', 'all', '--format', 'json', 'cluster'],
        'polaris' => ['polaris', 'audit', '--format', 'json'],
        'nuclei' => ['nuclei', '-target', '', '-json'],
    ];

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
                            'image' => self::IMAGES[$this->tool],
                            'command' => self::COMMANDS[$this->tool],
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
