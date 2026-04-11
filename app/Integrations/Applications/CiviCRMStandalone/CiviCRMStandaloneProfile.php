<?php

namespace App\Integrations\Applications\CiviCRMStandalone;

use App\Integrations\Applications\AppProfile;
use Modules\Kumuli\Entities\Applications\CiviCRM\CiviCRMRancherJobs;

class CiviCRMStandaloneProfile extends AppProfile
{
    protected $name = 'civicrm-standalone';

    protected $activation_type = 'chart';

    protected $compatibility = ['helm_chart', 'rancher'];

    protected $helm_chart = CiviCRMStandaloneChart::class;

    protected $jobs = CiviCRMRancherJobs::class;

    protected $role_groups = [
        'civicrm' => [
            'id' => 'civicrm',
            'label' => 'CiviCRM',
            'roles' => [
                'admin',
                'staff',
            ],
        ],
    ];

    protected $roles = [
        'admin' => [
            'id' => 'admin',
            'label' => 'Admininstrator',
            'role_group' => 'civicrm',
            'access_type' => 'standard',
        ],
        'staff' => [
            'id' => 'staff',
            'label' => 'User',
            'role_group' => 'civicrm',
            'access_type' => 'standard',
        ],
    ];

    protected $configurations = [
        'redis' => [
            'name' => 'redis',
            'type' => 'yaml',
            'persistent' => true,
            'default' => [
                'enabled' => true,
                'replica' => [
                    'replicaCount' => 1,
                    'persistence' => [
                        'enabled' => false,
                        'storageClass' => 'longhorn',
                    ],
                ],
                'master' => [
                    'persistence' => [
                        'enabled' => false,
                        'storageClass' => 'longhorn',
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
        'civicrm-strategy-type' => [
            'name' => 'civicrm-strategy-type',
            'type' => 'string',
            'default' => 'RollingUpdate',
            'persistent' => false,
            'validations' => 'in:RollingUpdate,Recreate',
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
        'civicrm-username' => [
            'name' => 'civicrm-username',
            'type' => 'string',
            'default' => 'admin',
            'persistent' => true,
            'validations' => 'nullable|string',
        ],
        'civicrm-mail-domain' => [
            'name' => 'civicrm-mail-domain',
            'type' => 'string',
            'default' => '',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'civicrm-mail-enabled' => [
            'name' => 'civicrm-mail-enabled',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'boolean',
        ],
        'civicrm-mail-fromAddress' => [
            'name' => 'civicrm-mail-fromAddress',
            'type' => 'string',
            'default' => '',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'civicrm-mail-smtp-authtype' => [
            'name' => 'civicrm-mail-smtp-authtype',
            'type' => 'string',
            'default' => 'PLAIN',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'civicrm-mail-smtp-host' => [
            'name' => 'civicrm-mail-smtp-host',
            'type' => 'string',
            'default' => '',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'civicrm-mail-smtp-name' => [
            'name' => 'civicrm-mail-smtp-name',
            'type' => 'string',
            'default' => '',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'civicrm-mail-smtp-password' => [
            'name' => 'civicrm-mail-smtp-password',
            'type' => 'password',
            'default' => '',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'civicrm-mail-smtp-port' => [
            'name' => 'civicrm-mail-smtp-port',
            'type' => 'int',
            'default' => 465,
            'persistent' => false,
            'validations' => 'nullable|integer',
        ],
        'civicrm-mail-smtp-secure' => [
            'name' => 'civicrm-mail-smtp-secure',
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
            'default' => 'https-redirect@kubernetescrd',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'mariadb' => [
            'name' => 'mariadb',
            'type' => 'yaml',
            'persistent' => true,
            'validations' => 'array',
            'default' => [
                'enabled' => false,
                'auth' => [
                    'password' => 'password',
                    'rootPassword' => 'root_password',
                ],
                'primary' => [
                    'persistence' => [
                        'enabled' => false,
                        'size' => '4Gi',
                        'storageClass' => '',
                    ],
                ],
            ],
        ],
        'civicrm-plugins' => [
            'name' => 'civicrm-plugins',
            'type' => 'string',
            'default' => '',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'civicrm-php-memory-limit' => [
            'name' => 'civicrm-php-memory-limit',
            'type' => 'string',
            'default' => '512M',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'civicrm-php-upload-limit' => [
            'name' => 'civicrm-php-upload-limit',
            'type' => 'string',
            'default' => '1G',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'civicrm-php-opcache-memory-consumption' => [
            'name' => 'civicrm-php-opcache-memory-consumption',
            'type' => 'int',
            'default' => '128',
            'persistent' => false,
            'validations' => 'required|integer',
        ],
        'cronjob-image' => [
            'name' => 'cronjob-image',
            'type' => 'string',
            'default' => 'example.com/docker/civicrm-cronjob:1.0',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'cronjob-resources-requests-cpu' => [
            'name' => 'cronjob-resources-requests-cpu',
            'type' => 'string',
            'default' => '200m',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'cronjob-resources-requests-memory' => [
            'name' => 'cronjob-resources-requests-memory',
            'type' => 'string',
            'default' => '200Mi',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'cronjob-resources-limits-cpu' => [
            'name' => 'cronjob-resources-limits-cpu',
            'type' => 'string',
            'default' => '400m',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'cronjob-resources-limits-memory' => [
            'name' => 'cronjob-resources-limits-memory',
            'type' => 'string',
            'default' => '500Mi',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'sample-data' => [
            'name' => 'sample-data',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'required|boolean',
        ],
    ];
}
