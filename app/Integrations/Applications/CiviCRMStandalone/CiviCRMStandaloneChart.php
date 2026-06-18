<?php

namespace App\Integrations\Applications\CiviCRMStandalone;

use App\Integrations\ServerManagers\Rancher\Charts\HelmChart;
use App\Support\Facades\Application;

class CiviCRMStandaloneChart extends HelmChart
{
    private $database_server;

    public $chart_name = 'civicrm-standalone';

    public function values(): array
    {
        $organization = $this->organization;
        $app_instance = Application::instance($this->app_instance);
        $version = $app_instance->version;

        if ($database_server = $app_instance->server('database')) {
            $database_server = $database_server->server;
        }
        $this->database_server = $database_server;

        return [
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
            'replicaCount' => $this->replicaCount(),
            'fullnameOverride' => $app_instance->setOverrideIfEmpty('chart.values.fullNameOverride', $this->chartName()),
            'externalDatabase' => [
                'database' => $app_instance->databasename,
                'host' => $database_server ? $database_server->internal_address : '',
                'password' => $this->app_instance->dbPassword(),
                'user' => $app_instance->databasename,
            ],
            'ingress' => [
                'annotations' => $app_instance->configuration('ingress-annotations'),
                'enabled' => ($this->appEnabled() && $app_instance->configuration('ingress-enabled')), // If app is disabled, also disable ingress so it's not accessible from internet without deleting app and losing data
                'hostname' => $app_instance->domain(),
                'tls' => $app_instance->configuration('ingress-tls'),
                'path' => '/',
            ],
            'mariadb' => $app_instance->configuration('mariadb', true),
            'persistence' => [
                'size' => $this->appStorage().'Gi',
                'accessModes' => [$app_instance->configuration('persistence-accessModes', true)],
                'storageClass' => $app_instance->configuration('persistence-storageClass', true),
                'enabled' => $app_instance->configuration('persistence-enabled', true),
                'existingClaim' => $app_instance->getOverride('pvc.override') ? $app_instance->getOverride('pvc.name') : '',
            ],
            'resources' => [
                'requests' => [
                    'cpu' => $app_instance->configuration('resources-requests-cpu'),
                    'memory' => $app_instance->configuration('resources-requests-memory'),
                ],
                'limits' => [
                    'cpu' => $app_instance->configuration('resources-limits-cpu'),
                    'memory' => $app_instance->configuration('resources-limits-memory'),
                ],
            ],
            'extraEnvVars' => $this->extraEnv(),
            'image' => [
                'registry' => $this->imageRegistry(),
                'pullPolicy' => $app_instance->configuration('image-pullPolicy'),
                'repository' => $version->setting('image_repo_name'),
                'tag' => $version->name,
                'pullSecrets' => $this->imagePullSecrets(),
            ],
            'updateStrategy' => [
                'type' => $app_instance->configuration('updateStrategy-type'),
            ],
            'cronjob' => [
                'enabled' => $app_instance->configuration('cronjob-enabled'),
                'sidecar' => [
                    'image' => $app_instance->configuration('cronjob-image'),
                    'resources' => [
                        'limits' => [
                            'cpu' => $app_instance->configuration('cronjob-resources-limits-cpu'),
                            'memory' => $app_instance->configuration('cronjob-resources-limits-memory'),
                        ],
                        'requests' => [
                            'cpu' => $app_instance->configuration('cronjob-resources-requests-cpu'),
                            'memory' => $app_instance->configuration('cronjob-resources-requests-memory'),
                        ],
                    ],
                ],
            ],
            'sidecars' => Application::profile('civicrm-standalone')->sidecars(),
            'civicrmEmail' => $app_instance->configuration('civicrm-email'),
            'civicrmPassword' => $app_instance->api_password(),
            'civicrmExtensions' => $app_instance->configuration('civicrm-extensions'),
            'civicrmUsername' => $app_instance->configuration('civicrm-username'),
            'civicrmSkipInstall' => false,
            'civicrmURL' => $app_instance->address(),
        ];
    }
}
