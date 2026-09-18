<?php

namespace App\Integrations\ServerManagers\Rancher\Charts\Job;

use Illuminate\Support\Str;

class CiviCRMStandaloneJobChart extends JobChart
{
    public $chart = [];

    // JobChart::persistentVolumeClaim() expects a Rancher-shaped `response.spec.resources` list to
    // find the PVC, which the HelmKubernetes driver doesn't return, so it resolves to null there.
    // The chart's PVC is named after the Helm release itself (see CiviCRMStandaloneChart's
    // `override.chart.civicrm-standalone.name`, used the same way for pod affinity), so fall back to that.
    public function persistentVolumeClaim()
    {
        return parent::persistentVolumeClaim() ?? $this->app_instance->getOverride('chart.civicrm-standalone.name');
    }

    public function run(array $command, array $env, ?array $args = null, ?string $job_name = null)
    {
        $app_instance = $this->app_instance;

        if (! $job_name) {
            $job_name = "{$this->organization->slug}-{$this->app_instance->application->slug}-job-".Str::lower(Str::random(10));
        }

        $this->chart = [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => [
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
                        'affinity' => [
                            'podAffinity' => [
                                'requiredDuringSchedulingIgnoredDuringExecution' => [
                                    [
                                        'topologyKey' => 'kubernetes.io/hostname',
                                        'labelSelector' => [
                                            'matchExpressions' => [
                                                [
                                                    'key' => 'app.kubernetes.io/instance',
                                                    'operator' => 'In',
                                                    'values' => [
                                                        $app_instance->setting('override.chart.civicrm-standalone.name'),
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'containers' => [[
                            'env' => $env,
                            'command' => $command,
                            'args' => $args,
                            'image' => $this->image(),
                            'imagePullPolicy' => 'Always',
                            'name' => 'civicrm-standalone-job',
                            'terminationMessagePath' => '/dev/termination-log',
                            'terminationMessagePolicy' => 'File',
                            'volumeMounts' => [
                                [
                                    'mountPath' => '/var/www/html/private',
                                    'name' => 'civicrm-data',
                                    'subPath' => 'private',
                                ],
                                [
                                    'mountPath' => '/var/www/html/public',
                                    'name' => 'civicrm-data',
                                    'subPath' => 'public',
                                ],
                                [
                                    'mountPath' => '/var/www/html/ext',
                                    'name' => 'civicrm-data',
                                    'subPath' => 'ext',
                                ],
                            ],
                        ]],
                        'dnsPolicy' => 'ClusterFirst',
                        'imagePullSecrets' => array_map(fn ($name) => ['name' => $name], $this->imagePullSecrets()),
                        'restartPolicy' => 'Never',
                        'schedulerName' => 'default-scheduler',
                        'serviceAccount' => 'default',
                        'serviceAccountName' => 'default',
                        'terminationGracePeriodSeconds' => 30,
                        'volumes' => [[
                            'name' => 'civicrm-data',
                            'persistentVolumeClaim' => [
                                'claimName' => $this->persistentVolumeClaim(),
                            ],
                        ]],
                    ],
                ],
            ],
        ];
    }
}
