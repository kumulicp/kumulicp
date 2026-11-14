<?php

namespace App\Integrations\ServerManagers\Kubernetes\Charts\Job;

use App\Integrations\ServerManagers\Kubernetes\Charts\NextcloudChart;
use App\Support\Facades\Application;
use Illuminate\Support\Str;

class NextcloudJobChart extends JobChart
{
    public $chart = [];

    /**
     * The Kubernetes driver's release lookup returns chart values, not the
     * release's resources, so the claim is not found by the generic lookup.
     * The Nextcloud chart names its claim <fullname>-nextcloud unless an
     * existing claim is given, and the fullname is set by NextcloudChart.
     */
    public function persistentVolumeClaim()
    {
        // The job runs against the hub's release on a shared plan
        $release_instance = $this->app_instance->sharedPlanParent() ?? $this->app_instance;

        if ($claim = $release_instance->setting('existing_claim')) {
            return $claim;
        }

        if ($fullname = $release_instance->setting('override.chart.values.fullNameOverride')) {
            return "{$fullname}-nextcloud";
        }

        return parent::persistentVolumeClaim();
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
                'annotations' => [
                    'test' => 'annonation',
                ],
                'labels' => $this->labels(),
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
                    'metadata' => [
                        'labels' => $this->labels(),
                    ],
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
                            'image' => $this->image(),
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
                        'imagePullSecrets' => array_map(fn ($name) => ['name' => $name], $this->imagePullSecrets()),
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

        // Same contexts as the release's pods (NextcloudChart). The pod-level
        // `allowPrivilegeEscalation` above is a container field Kubernetes ignores,
        // so it is only dropped here, where the real one is set on the container.
        if (Application::instance($app_instance)->configuration('restricted-security-context')) {
            $spec = &$this->chart['spec']['template']['spec'];
            $spec['securityContext'] = NextcloudChart::restrictedPodSecurityContext();
            $spec['containers'][0]['securityContext'] = NextcloudChart::restrictedContainerSecurityContext();
        }
    }
}
