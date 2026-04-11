<?php

namespace App\Integrations\Applications\Nextcloud;

use App\Integrations\Applications\AppProfile;
use App\Integrations\Applications\Nextcloud\Features\CalendarAddon;
use App\Integrations\Applications\Nextcloud\Features\ContactsAddon;
use App\Integrations\Applications\Nextcloud\Features\DeckAddon;
use App\Integrations\Applications\Nextcloud\Features\TalkAddon;
use App\Integrations\ServerManagers\Rancher\Charts\NextcloudChart;

class NextcloudProfile extends AppProfile
{
    protected $name = 'nextcloud';

    protected $activation_type = 'chart';

    protected $compatibility = ['rancher', 'openid', 'ldap', 'additional_user_storage', 'additional_storage', 'helm_chart'];

    protected $helm_chart = NextcloudChart::class;

    protected $jobs = NextcloudRancherJobs::class;

    protected $envs = [
        NextcloudEnvVars::class,
    ];

    protected $features = [
        'calendar' => CalendarAddon::class,
        'contacts' => ContactsAddon::class,
        'deck' => DeckAddon::class,
        'spreed' => TalkAddon::class,
    ];

    protected $role_groups = [
        'user' => [
            'id' => 'user',
            'label' => 'User',
            'roles' => [
                'standard',
                'basic',
            ],
        ],
    ];

    protected $roles = [
        'standard' => [
            'id' => 'standard',
            'label' => 'Standard',
            'role_group' => 'user',
        ],
        'basic' => [
            'id' => 'basic',
            'label' => 'Volunteer',
            'role_group' => 'user',
        ],
    ];

    protected $recommendations = [
        'image_repo' => 'library/nextcloud',
        'image_version' => '33.0.1',
        'image_registry' => 'docker.io',
        'helm_chart_repo' => 'https://nextcloud.github.io/helm/',
        'helm_chart_name' => 'nextcloud',
        'helm_chart_version' => '9.0.5',
    ];

    protected $configurations = [
        'redis' => [
            'name' => 'redis',
            'type' => 'yaml',
            'persistent' => true,
            'default' => [
                'enabled' => true,
                'image' => [
                    'registry' => 'docker.io',
                    'tag' => '8.0.1-debian-12-r1',
                ],
                'replica' => [
                    'replicaCount' => 1,
                    'persistence' => [
                        'enabled' => false,
                        'storageClass' => '',
                    ],
                ],
                'master' => [
                    'persistence' => [
                        'enabled' => false,
                        'storageClass' => '',
                    ],
                ],
            ],
            'validations' => 'nullable|array',
        ],
        'replicaCount' => [
            'name' => 'replicaCount',
            'type' => 'int',
            'persistent' => true,
            'default' => 1,
            'validations' => 'integer|required',
        ],
        'persistence-enabled' => [
            'name' => 'persistence-enabled',
            'type' => 'bool',
            'persistent' => true,
            'default' => false,
            'validations' => 'boolean',
        ],
        'persistence-storageClass' => [
            'name' => 'persistence-storageClass',
            'type' => 'string',
            'persistent' => true,
            'validations' => 'nullable',
            'default' => '',
        ],
        'persistence-existingClaim' => [
            'name' => 'persistence-existingClaim',
            'type' => 'string',
            'persistent' => true,
            'validations' => 'nullable',
            'default' => '',
        ],
        'persistence-accessMode' => [
            'name' => 'persistence-accessMode',
            'type' => 'string',
            'default' => 'ReadWriteOnce',
            'persistent' => true,
            'validations' => 'in:ReadWriteOnce,ReadWriteMany|nullable',
        ],
        'persistence-numberOfReplicas' => [
            'name' => 'persistence-numberOfReplicas',
            'type' => 'int',
            'default' => 1,
            'persistent' => false,
            'validations' => 'nullable|integer',
        ],
        'resources-requests-cpu' => [
            'name' => 'resources-requests-cpu',
            'type' => 'string',
            'default' => '500m',
            'persistent' => false,
            'validations' => '',
        ],
        'resources-requests-memory' => [
            'name' => 'resources-requests-memory',
            'type' => 'string',
            'default' => '500Mi',
            'persistent' => false,
            'validations' => '',
        ],
        'resources-limits-cpu' => [
            'name' => 'resources-limits-cpu',
            'type' => 'string',
            'default' => '1250m',
            'persistent' => false,
            'validations' => '',
        ],
        'resources-limits-memory' => [
            'name' => 'resources-limits-memory',
            'type' => 'string',
            'default' => '1Gi',
            'persistent' => false,
            'validations' => '',
        ],
        'nextcloud-strategy-type' => [
            'name' => 'nextcloud-strategy-type',
            'type' => 'string',
            'default' => 'RollingUpdate',
            'persistent' => false,
            'validations' => 'in:RollingUpdate,Recreate',
        ],
        'startupProbe-enabled' => [
            'name' => 'startupProbe-enabled',
            'type' => 'bool',
            'default' => true,
            'persistent' => false,
            'validations' => 'boolean',
        ],
        'startupProbe-initialDelaySeconds' => [
            'name' => 'startupProbe-initialDelaySeconds',
            'type' => 'int',
            'default' => 10,
            'persistent' => false,
            'validations' => 'nullable|integer',
        ],
        'startupProbe-periodSeconds' => [
            'name' => 'startupProbe-periodSeconds',
            'type' => 'int',
            'default' => 10,
            'persistent' => false,
            'validations' => 'nullable|integer',
        ],
        'startupProbe-timeoutSeconds' => [
            'name' => 'startupProbe-timeoutSeconds',
            'type' => 'int',
            'default' => 10,
            'persistent' => false,
            'validations' => 'nullable|integer',
        ],
        'cronjob' => [
            'name' => 'cronjob',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'boolean',
        ],
        'cronjob-resources-requests-cpu' => [
            'name' => 'cronjob-resources-requests-cpu',
            'type' => 'string',
            'default' => '500m',
            'persistent' => false,
            'validations' => '',
        ],
        'cronjob-resources-requests-memory' => [
            'name' => 'cronjob-resources-requests-memory',
            'type' => 'string',
            'default' => '500Mi',
            'persistent' => false,
            'validations' => '',
        ],
        'cronjob-resources-limits-cpu' => [
            'name' => 'cronjob-resources-limits-cpu',
            'type' => 'string',
            'default' => '1250m',
            'persistent' => false,
            'validations' => '',
        ],
        'cronjob-resources-limits-memory' => [
            'name' => 'cronjob-resources-limits-memory',
            'type' => 'string',
            'default' => '1Gi',
            'persistent' => false,
            'validations' => '',
        ],
        'mariadb' => [
            'name' => 'mariadb',
            'type' => 'yaml',
            'persistent' => true,
            'validations' => 'nullable|array',
            'default' => [
                'enabled' => false,
                'auth' => [
                    'rootPassword' => 'changme',
                ],
                'db' => [
                    'database' => 'nextcloud',
                    'username' => 'nextcloud',
                    'password' => 'changeme',
                ],
                'existingSecret' => '',
                'architecture' => 'standalone',
                'primary' => [
                    'persistence' => [
                        'enabled' => false,
                        'existingClaim' => '',
                        'storageClass' => '',
                        'accessMode' => 'ReadWriteOnce',
                        'size' => '8Gi',
                    ],
                ],
            ],
        ],
        'rbac-enabled' => [
            'name' => 'rbac-enabled',
            'type' => 'bool',
            'default' => true,
            'persistent' => true,
            'validations' => 'boolean',
        ],
        'hpa-cputhreshold' => [
            'name' => 'hpa-cputhreshold',
            'type' => 'int',
            'default' => 60,
            'persistent' => false,
            'validations' => 'nullable|integer',
        ],
        'hpa-enabled' => [
            'name' => 'hpa-enabled',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'boolean',
        ],
        'hpa-maxPods' => [
            'name' => 'hpa-maxPods',
            'type' => 'int',
            'default' => 10,
            'persistent' => false,
            'validations' => 'nullable|integer',
        ],
        'hpa-minPods' => [
            'name' => 'hpa-minPods',
            'type' => 'int',
            'default' => 1,
            'persistent' => false,
            'validations' => 'nullable|integer',
        ],
        'externalDatabase-enabled' => [
            'name' => 'externalDatabase-enabled',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'boolean',
        ],
        'metrics-enabled' => [
            'name' => 'metrics-enabled',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'boolean',
        ],
        'metrics-https' => [
            'name' => 'metrics-https',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'boolean',
        ],
        'username' => [
            'name' => 'username',
            'type' => 'string',
            'default' => 'admin',
            'persistent' => true,
            'validations' => 'nullable|string',
        ],
        'nextcloud-mail-domain' => [
            'name' => 'nextcloud-mail-domain',
            'type' => 'string',
            'default' => '',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'nextcloud-mail-enabled' => [
            'name' => 'nextcloud-mail-enabled',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'boolean',
        ],
        'nextcloud-mail-fromAddress' => [
            'name' => 'nextcloud-mail-fromAddress',
            'type' => 'string',
            'default' => '',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'nextcloud-mail-smtp-authtype' => [
            'name' => 'nextcloud-mail-smtp-authtype',
            'type' => 'string',
            'default' => 'PLAIN',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'nextcloud-mail-smtp-host' => [
            'name' => 'nextcloud-mail-smtp-host',
            'type' => 'string',
            'default' => '',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'nextcloud-mail-smtp-name' => [
            'name' => 'nextcloud-mail-smtp-name',
            'type' => 'string',
            'default' => '',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'nextcloud-mail-smtp-password' => [
            'name' => 'nextcloud-mail-smtp-password',
            'type' => 'password',
            'default' => '',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'nextcloud-mail-smtp-port' => [
            'name' => 'nextcloud-mail-smtp-port',
            'type' => 'int',
            'default' => 465,
            'persistent' => false,
            'validations' => 'nullable|integer',
        ],
        'nextcloud-mail-smtp-secure' => [
            'name' => 'nextcloud-mail-smtp-secure',
            'type' => 'string',
            'default' => 'ssl',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'ingress-enabled' => [
            'name' => 'ingress-enabled',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'boolean',
        ],
        'ingress-tls' => [
            'name' => 'ingress-tls',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'boolean',
        ],
        'ingress-annotation-cluster_issuer' => [
            'name' => 'ingress-annotation-cluster_issuer',
            'type' => 'string',
            'default' => 'letsencrypt-production',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'ingress-annotation-router_middlewares' => [
            'name' => 'ingress-annotation-router_middlewares',
            'type' => 'string',
            'default' => 'https-redirect@kubernetescrd,dav-redirect@kubernetescrd,hsts@kubernetescrd',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'enable-sso' => [
            'name' => 'enable-sso',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'nullable|boolean',
        ],
        'oidc-scope' => [
            'name' => 'oidc-scope',
            'type' => 'string',
            'default' => 'email profile openid offline_access',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'oidc-mapping-user-id' => [
            'name' => 'oidc-mapping-user-id',
            'type' => 'string',
            'default' => 'preferred_username',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'oidc-multiple-backends' => [
            'name' => 'oidc-multiple-backends',
            'type' => 'bool',
            'default' => true,
            'persistent' => false,
            'validations' => 'nullable|boolean',
        ],
        'oidc-auto-provision' => [
            'name' => 'oidc-auto-provision',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'nullable|boolean',
        ],
        'oidc-mapping-email' => [
            'name' => 'oidc-mapping-email',
            'type' => 'string',
            'default' => 'email',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'oidc-mapping-name' => [
            'name' => 'oidc-mapping-name',
            'type' => 'string',
            'default' => 'name',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'imaginary-enabled' => [
            'name' => 'imaginary-enabled',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'nullable|boolean',
        ],
        'nextcloud-php-memory-limit' => [
            'name' => 'nextcloud-php-memory-limit',
            'type' => 'string',
            'default' => '512M',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'nextcloud-php-upload-limit' => [
            'name' => 'nextcloud-php-upload-limit',
            'type' => 'string',
            'default' => '1G',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'nextcloud-php-opcache-memory-consumption' => [
            'name' => 'nextcloud-php-opcache-memory-consumption',
            'type' => 'int',
            'default' => '128',
            'persistent' => false,
            'validations' => 'required|integer',
        ],
    ];
}
