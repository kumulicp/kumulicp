<?php

namespace App\Integrations\ServerManagers\Rancher\Charts\Job;

use Illuminate\Support\Str;

class NextcloudJobChart extends JobChart
{
    public $chart = [];

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
                                                        $app_instance->setting('override.chart.nextcloud.name'),
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
                            'image' => $app_instance->version->setting('image_repo_name').':'.$app_instance->version->name,
                            'imagePullPolicy' => 'Always',
                            'name' => 'nextcloud-job',
                            'ports' => [[
                                'containerPort' => 80,
                                'name' => 'http',
                                'protocol' => 'TCP',
                            ]],
                            'terminationMessagePath' => '/dev/termination-log',
                            'terminationMessagePolicy' => 'File',
                            'volumeMounts' => [
                                [
                                    'mountPath' => '/var/www/',
                                    'name' => 'nextcloud-storage',
                                    'subPath' => 'root',
                                ],
                                [
                                    'mountPath' => '/var/www/html',
                                    'name' => 'nextcloud-storage',
                                    'subPath' => 'html',
                                ],
                                [
                                    'mountPath' => '/var/www/html/config',
                                    'name' => 'nextcloud-storage',
                                    'subPath' => 'config',
                                ],
                                [
                                    'mountPath' => '/var/www/html/custom_apps',
                                    'name' => 'nextcloud-storage',
                                    'subPath' => 'custom_apps',
                                ],
                                [
                                    'mountPath' => '/var/www/tmp',
                                    'name' => 'nextcloud-storage',
                                    'subPath' => 'tmp',
                                ],
                                [
                                    'mountPath' => '/var/www/html/themes',
                                    'name' => 'nextcloud-storage',
                                    'subPath' => 'themes',
                                ],
                                [
                                    'mountPath' => '/var/www/html/data',
                                    'name' => 'nextcloud-storage',
                                    'subPath' => 'data',
                                ],
                            ],
                        ]],
                        'dnsPolicy' => 'ClusterFirst',
                        'restartPolicy' => 'Never',
                        'schedulerName' => 'default-scheduler',
                        'securityContext' => [
                            'fsGroup' => 33,
                            'allowPrivilegeEscalation' => true,
                            'runAsUser' => 33,
                        ],
                        'serviceAccount' => 'default',
                        'serviceAccountName' => 'default',
                        'terminationGracePeriodSeconds' => 30,
                        'volumes' => [[
                            'name' => 'nextcloud-storage',
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
