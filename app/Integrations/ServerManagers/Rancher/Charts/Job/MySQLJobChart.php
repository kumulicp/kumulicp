<?php

namespace App\Integrations\ServerManagers\Rancher\Charts\Job;

use Illuminate\Support\Str;

class MySQLJobChart extends JobChart
{
    public $chart = [];

    public function run(array $command, array $env, ?array $args = null, ?string $job_name = null)
    {
        $app_instance = $this->app_instance;

        if (! $job_name) {
            $job_name = "{$this->organization->slug}-{$this->app_instance->name}-job-".Str::lower(Str::random(10));
        }

        $this->chart = [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => [
                'annotations' => [
                    'test' => 'annonation',
                ],
                'labels' => [
                    'test' => 'label',
                ],
                'name' => $job_name,
                'namespace' => $app_instance->organization->slug,
            ],
            'spec' => [
                'backoffLimit' => 6,
                'completionMode' => 'NonIndexed',
                'completions' => 1,
                'parallelism' => 1,
                'ttlSecondsAfterFinished' => 600,
                'suspend' => false,
                'template' => [
                    'spec' => [
                        'containers' => [[
                            'env' => $env,
                            'command' => $command,
                            'args' => $args,
                            'envFrom' => [
                                [
                                    'secretRef' => [
                                        'name' => 'kmanager-dev-backup-secret',
                                        'optional' => false,
                                    ],
                                ],
                            ],
                            'image' => '',
                            'imagePullPolicy' => 'Always',
                            'name' => 'mysql-job',
                            'terminationMessagePath' => '/dev/termination-log',
                            'terminationMessagePolicy' => 'File',
                        ]],
                        'dnsPolicy' => 'ClusterFirst',
                        'restartPolicy' => 'Never',
                        'schedulerName' => 'default-scheduler',
                        'serviceAccount' => 'default',
                        'serviceAccountName' => 'default',
                        'terminationGracePeriodSeconds' => 30,
                    ],
                ],
            ],
        ];
    }
}
