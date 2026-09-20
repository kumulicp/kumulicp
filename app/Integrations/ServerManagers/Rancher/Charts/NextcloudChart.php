<?php

namespace App\Integrations\ServerManagers\Rancher\Charts;

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

        $ingress_enabled = $app_instance->configuration('ingress-enabled', true);

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
                'pullPolicy' => $app_instance->configuration('image-pullPolicy'),
                'registry' => $this->imageRegistry(),
                'repository' => $version->setting('image_repo_name'),
                'tag' => $version->name,
                'pullSecrets' => $this->imagePullSecrets(),
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
                'enabled' => $app_instance->configuration('ingress-enabled', true) ?? $this->appEnabled(), // If app is disabled, also disable ingress so it's not accessible from internet without deleting app and losing data
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
                'configs' => [
                    // Always non-empty on purpose: the chart's own templates only
                    // create the config ConfigMap/volume, and mount 'defaultConfigs'
                    // entries (e.g. imaginary.config.php below), when
                    // nextcloud.configs itself is non-empty -- so this acts as a
                    // guaranteed placeholder even if every other entry here becomes
                    // conditional later.
                    'blank.config.php' => "<?php\n\$CONFIG = array (\n);\n",
                    'previews.config.php' => $this->previewsConfig(),
                ],
                'defaultConfigs' => [
                    'imaginary.config.php' => $app_instance->configuration('imaginary-enabled'),
                ],
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
                'extraSidecarContainers' => $this->sidecars(),
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
        ];
    }

    // Raw config.php contents mounted at config/previews.config.php -- see
    // https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/config_sample_php_parameters.html#previews
    // Kept separate from the chart's own imaginary.config.php (activated via
    // 'defaultConfigs' above) so the two don't fight over the same keys --
    // that one already sets preview_imaginary_url/enable_previews/enabledPreviewProviders.
    private function previewsConfig(): string
    {
        $app_instance = Application::instance($this->app_instance);

        $max_x = $app_instance->configuration('preview-max-x');
        $max_y = $app_instance->configuration('preview-max-y');
        $max_filesize_image = $app_instance->configuration('preview-max-filesize-image');
        $max_memory = $app_instance->configuration('preview-max-memory');
        $concurrency_new = $app_instance->configuration('preview-concurrency-new');
        $concurrency_all = $app_instance->configuration('preview-concurrency-all');

        return <<<PHP
        <?php
        \$CONFIG = array (
          'preview_max_x' => {$max_x},
          'preview_max_y' => {$max_y},
          'preview_max_filesize_image' => {$max_filesize_image},
          'preview_max_memory' => {$max_memory},
          'preview_concurrency_new' => {$concurrency_new},
          'preview_concurrency_all' => {$concurrency_all},
        );
        PHP;
    }
}
