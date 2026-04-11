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
            'fullnameOverride' => $app_instance->setOverrideIfEmpty('chart.values.fullNameOverride', $organization->slug.'-'.$this->chartName()),
            'containerSecurityContext' => [
                'enabled' => false,
                'runAsUser' => 0,
                'runAsNonRoot' => false,
            ],
            'externalDatabase' => [
                'enabled' => $database_server ? true : false,
                'database' => $app_instance->databasename,
                'host' => $database_server ? $database_server->internal_address : '',
                'password' => $organization->secretpw,
                'user' => $app_instance->databasename,
            ],
            'ingress' => [
                'annotations' => $app_instance->configuration('ingress-tls') ? [
                    'cert-manager.io/cluster-issuer' => $app_instance->configuration('ingress-annotation-cluster_issuer'),
                    'traefik.ingress.kubernetes.io/router.middlewares' => $app_instance->configuration('ingress-annotation-traefik_middlewares'),
                ] : [],
                'enabled' => ($this->appEnabled() && $app_instance->configuration('ingress-enabled')), // If app is disabled, also disable ingress so it's not accessible from internet without deleting app and losing data
                'hostname' => $app_instance->domain(),
                'tls' => $app_instance->configuration('ingress-tls'),
                'path' => '/',
            ],
            'mariadb' => $app_instance->configuration('mariadb', true),
            'persistence' => [
                'size' => $this->appStorage().'Gi',
                'accessMode' => $app_instance->configuration('persistence-accessMode', true),
                // 'accessModes' => $app_instance->configuration('persistence-accessModes', true),
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
            // 'extraEnvVarsSecret' => 'civicrm-env-secret',
            'service' => [
                // 'sessionAffinity' => '',
                'type' => 'ClusterIP',
                'ports' => [
                    'http' => 8080,
                    'https' => 8433,
                ],
            ],
            'image' => [
                'registry' => $version->setting('image_registry'),
                'debug' => $app_instance->configuration('image-debug'),
                'pullPolicy' => $app_instance->configuration('image-pullPolicy'),
                'repository' => $version->setting('image_repo_name'),
                'tag' => $version->name,
            ],
            'updateStrategy' => [
                'rollingUpdate' => $app_instance->configuration('updateStrategy-rollingUpdate'),
                'type' => $app_instance->configuration('updateStrategy-type'),
            ],
            'customReadinessProbe' => [
                'failureThreshold' => $app_instance->configuration('customReadinessProbe-failureThreshold'),
                'httpGet' => [
                    'path' => '/',
                    'port' => 'http',
                    'scheme' => 'HTTP',
                ],
                'initialDelaySeconds' => $app_instance->configuration('customReadinessProbe-initialDelaySeconds'),
                'periodSeconds' => $app_instance->configuration('customReadinessProbe-periodSeconds'),
                'successThreshold' => $app_instance->configuration('customReadinessProbe-successThreshold'),
                'timeoutSeconds' => $app_instance->configuration('customReadinessProbe-timeoutSeconds'),
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
