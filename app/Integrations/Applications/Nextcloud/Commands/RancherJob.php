<?php

namespace App\Integrations\Applications\Nextcloud\Commands;

use App\Integrations\Applications\Nextcloud\Nextcloud;

class RancherJob extends Nextcloud
{
    public function run(array $command)
    {
        $app_instance = $this->app_instance;
        $name = "{$app_instance->organization->slug}-nextcloud-job-{$app_instance->id}";

        return [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => [
                'annotations' => [],
                'labels' => [],
                'name' => $name,
                'namespace' => $app_instance->organization->slug,
            ],
            'spec' => [
                'backoffLimit' => 6,
                'completionMode' => 'NonIndexed',
                'completions' => 1,
                'parallelism' => 1,
                'selector' => [
                    'matchLabels' => [],
                ],
                'suspend' => false,
                'template' => [
                    'metadata' => [
                        'labels' => [],
                    ],
                    'spec' => [
                        'containers' => [[
                            'command' => $command,
                            'image' => $app_instance->version->setting('repo_name'),
                            'imagePullPolicy' => 'IfNotPresent',
                            'name' => 'nextcloud-job',
                            'ports' => [[
                                'containerPort' => 80,
                                'name' => 'http',
                                'protocol' => 'TCP',
                            ]],
                            'resources' => [],
                            'terminationMessagePath' => '/dev/termination-log',
                            'terminationMessagePolicy' => 'File',
                            'volumeMounts' => [[
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
                        'serviceAccount' => 'nextcloud-serviceaccount',
                        'serviceAccountName' => 'nextcloud-serviceaccount',
                        'terminationGracePeriodSeconds' => 30,
                        'volumes' => [[
                            'name' => 'nextcloud-storage',
                            'persistentVolumeClaim' => [
                                'claimName' => 'kdev-nextcloud-10-nextcloud',
                            ],
                        ]],
                    ],
                ],
            ],
        ];
    }
}
