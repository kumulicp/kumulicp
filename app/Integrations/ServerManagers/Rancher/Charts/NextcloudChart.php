<?php

namespace App\Integrations\ServerManagers\Rancher\Charts;

use App\Integrations\ServerManagers\Rancher\Charts\Middleware\NextcloudDavRedirect;
use App\Integrations\ServerManagers\Rancher\Services\DomainMiddlewareService;
use App\Support\Facades\Application;
use Illuminate\Support\Facades\Crypt;

class NextcloudChart extends HelmChart
{
    public $chart_name = 'nextcloud';

    public function values(): array
    {
        $organization = $this->organization;
        $app_instance = Application::instance($this->app_instance);
        $version = $app_instance->version;
        $this->app_instance_select = $app_instance;

        $ingress_enabled = $app_instance->configuration('ingress-enabled', true);

        if ($ingress_enabled) {
            $middleware_service = new DomainMiddlewareService($organization, $app_instance->web_server, $this->app_instance);
            $dav = new NextcloudDavRedirect($organization, $this->app_instance);
            $middleware_service->updateChart($dav);
        }

        $this->web_server = $app_instance->server('web')->serverInfo();
        $database_server = '';
        if ($organization_database_server = $app_instance->server('database')) {
            $database_server = $organization_database_server->serverInfo();
        }

        $app_settings = $version->settings;

        $namespace = $organization->slug;

        return [
            'replicaCount' => $this->replicaCount(),
            'fullnameOverride' => $app_instance->setOverrideIfEmpty('chart.values.fullNameOverride', $organization->slug.'-nextcloud-'.$app_instance->id),
            'image' => [
                'pullPolicy' => 'IfNotPresent',
                'registry' => $version->setting('image_registry'),
                'repository' => $version->setting('image_repo_name'),
                'tag' => $version->name,
            ],
            'externalDatabase' => [
                'enabled' => $database_server ? true : false,
                'database' => $database_server ? $app_instance->databasename : '',
                'host' => $database_server ? $database_server->internal_address : '',
                'password' => $database_server ? $organization->secretpw : '',
                'user' => $database_server ? $app_instance->databasename : '',
            ],
            'hpa' => [
                'cputhreshold' => $app_instance->configuration('hpa-cputhreshold'),
                'enabled' => $app_instance->configuration('hpa-enabled'),
                'maxPods' => $app_instance->configuration('hpa-maxPods'),
                'minPods' => $app_instance->configuration('hpa-minPods'),
            ],
            'imaginary' => [
                'enabled' => $app_instance->configuration('imaginary-enabled'),
                'replicaCount' => $app_instance->configuration('imaginary-replicaCount'),
            ],
            'ingress' => [
                'annotations' => $app_instance->configuration('ingress-tls') ? [
                    'cert-manager.io/cluster-issuer' => $app_instance->configuration('ingress-annotation-cluster_issuer', true),
                    'traefik.ingress.kubernetes.io/router.middlewares' => $app_instance->configuration('ingress-annotation-router_middlewares'),
                ] : ($ingress_enabled ? [
                    'traefik.ingress.kubernetes.io/router.middlewares' => $app_instance->configuration('ingress-annotation-router_middlewares'),
                ] : []),
                'enabled' => $app_instance->configuration('ingress-enabled', true) ?? $this->appEnabled(), // If app is disabled, also disable ingress so it's not accessible from intwordpresschernet without deleting app and losing data
                'tls' => $app_instance->configuration('ingress-tls') ? [
                    [
                        'hosts' => [$app_instance->domain()],
                        'secretName' => $namespace.'-nextcloud-ingress-tls-secret',
                    ],
                ] : [],
                'pathType' => 'Prefix',
            ],
            'internalDatabase' => [
                'enabled' => false,
            ],
            'mariadb' => $app_instance->configuration('mariadb', true),
            'metrics' => [
                'enabled' => $app_instance->configuration('metrics-enabled', true),
                'https' => $app_instance->configuration('metrics-https', true),
            ],
            'nextcloud' => [
                // 'configs' => (object) [],
                // 'defaultConfigs' => [
                //     'imaginary.config.php' => $app_instance->configuration('imaginary-enabled'),
                // ],
                'host' => $app_instance->domain(),
                // 'existingSecret' => [
                //     'enabled' => true,
                //     'secretName' => 'nextcloud-env-secret'
                // ],
                'password' => $app_instance->api_password(),
                'username' => $app_instance->configuration('username', true),
                'extraEnv' => $this->extraEnv(),
                'mail' => [
                    'domain' => $app_instance->configuration('nextcloud-mail-domain', true),
                    'enabled' => $app_instance->configuration('nextcloud-mail-enabled', true),
                    'fromAddress' => $app_instance->configuration('nextcloud-mail-fromAddress', true),
                    'smtp' => [
                        'authtype' => $app_instance->configuration('nextcloud-mail-smtp-authtype', true),
                        'host' => $app_instance->configuration('nextcloud-mail-smtp-host', true),
                        'name' => $app_instance->configuration('nextcloud-mail-smtp-name', true),
                        'password' => $app_instance->configuration('nextcloud-mail-smtp-password') ? Crypt::decryptString($app_instance->configuration('nextcloud-mail-smtp-password', true)) : '',
                        'port' => $app_instance->configuration('nextcloud-mail-smtp-port', true),
                        'secure' => $app_instance->configuration('nextcloud-mail-smtp-secure', true),
                    ],
                ],
                'strategy' => [
                    'type' => $app_instance->configuration('nextcloud-strategy-type', true),
                ],
            ],
            'persistence' => [
                'enabled' => $app_instance->configuration('persistence-enabled', true),
                'storageClass' => $app_instance->configuration('persistence-storageClass', true),
                'size' => $this->appStorage().'Gi',
                'existingClaim' => $app_instance->setting('existing_claim', ''),
                'accessMode' => $app_instance->configuration('persistence-accessMode', true),
            ],
            'rbac' => [
                'enabled' => $app_instance->configuration('rbac-enabled', true),
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
            'redis' => $app_instance->configuration('redis'),
            'startupProbe' => [
                'enabled' => $app_instance->configuration('startupProbe-enabled'),
                'initialDelaySeconds' => $app_instance->configuration('startupProbe-initialDelaySeconds'),
                'periodSeconds' => $app_instance->configuration('startupProbe-periodSeconds'),
                'timeoutSeconds' => $app_instance->configuration('startupProbe-timeoutSeconds'),
            ],
            'cronjob' => [
                'enabled' => $app_instance->configuration('cronjob'),
                'resources' => [
                    'requests' => [
                        'cpu' => $app_instance->configuration('cronjob-resources-requests-cpu'),
                        'memory' => $app_instance->configuration('cronjob-resources-requests-memory'),
                    ],
                    'limits' => [
                        'cpu' => $app_instance->configuration('cronjob-resources-limits-cpu'),
                        'memory' => $app_instance->configuration('cronjob-resources-limits-memory'),
                    ],
                ],
            ],
            'extraSidecarContainers' => $this->sidecars(),
        ];
    }
}
