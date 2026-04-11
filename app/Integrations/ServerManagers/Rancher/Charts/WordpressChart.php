<?php

namespace App\Integrations\ServerManagers\Rancher\Charts;

use App\Support\Facades\Application;

class WordpressChart extends HelmChart
{
    private $database_server;

    public $chart_name = 'wordpress';

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
                                            $app_instance->setting('override.chart.wordpress.name'),
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
            'podSecurityContext' => [
                'fsGroup' => 0,
            ],
            'containerPorts' => [
                'http' => 80,
                'https' => 433,
            ],
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
            ],
            'mariadb' => $app_instance->configuration('mariadb', true),
            'persistence' => [
                'size' => $this->appStorage().'Gi',
                'accessMode' => $app_instance->configuration('persistence-accessMode', true),
                'accessModes' => $app_instance->configuration('persistence-accessModes', true),
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
            // 'extraEnvVarsSecret' => 'wordpress-env-secret',
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
                    'path' => '/wp-login.php',
                    'port' => 'http',
                    'scheme' => 'HTTP',
                ],
                'initialDelaySeconds' => $app_instance->configuration('customReadinessProbe-initialDelaySeconds'),
                'periodSeconds' => $app_instance->configuration('customReadinessProbe-periodSeconds'),
                'successThreshold' => $app_instance->configuration('customReadinessProbe-successThreshold'),
                'timeoutSeconds' => $app_instance->configuration('customReadinessProbe-timeoutSeconds'),
            ],
            'sidecars' => $this->sidecars(),
            'wordpressBlogName' => $organization->name,
            'wordpressEmail' => $app_instance->configuration('wordpress-email'),
            'wordpressFirstName' => $app_instance->configuration('wordpress-firstname'),
            'wordpressLastName' => $app_instance->configuration('wordpress-lastname'),
            'wordpressPassword' => $app_instance->api_password(),
            'wordpressPlugins' => $app_instance->configuration('wordpress-plugins'),
            'wordpressUsername' => $app_instance->configuration('wordpress-username'),
            'wordpressScheme' => $app_instance->configuration('ingress-tls') ? 'https' : 'http',
        ];
    }
}
