<?php

namespace App\Integrations\Applications\Wordpress;

use App\Integrations\Applications\AppProfile;
use App\Integrations\ServerManagers\Rancher\Charts\WordpressChart;

class WordpressProfile extends AppProfile
{
    protected $name = 'wordpress';

    protected $activation_type = 'chart';

    protected $compatibility = ['helm_chart', 'rancher'];

    protected $helm_chart = WordpressChart::class;

    protected $jobs = WordpressRancherJobs::class;

    protected $sso_redirect_path = [
        'matching_mode' => 'regex',
        'path' => null,
    ];

    protected $role_groups = [
        'content' => [
            'id' => 'content',
            'label' => 'Content',
            'roles' => [
                'administrator',
                'editor',
                'author',
                'contributor',
                'subscriber',
            ],
        ],
    ];

    protected $envs = [
        WordpressEnvVars::class,
    ];

    protected $roles = [
        'administrator' => [
            'id' => 'administrator',
            'label' => 'Administrator',
            'role_group' => 'content',
            'access_type' => 'standard',
            'description' => 'Can manage Wordpress users, plugins, themes, content and more',
        ],
        'editor' => [
            'id' => 'editor',
            'label' => 'Editor',
            'role_group' => 'content',
            'access_type' => 'standard',
            'description' => 'Manges the content published on Wordpress, including creating, editing and deleting posts and pages',
        ],
        'author' => [
            'id' => 'author',
            'label' => 'Author',
            'role_group' => 'content',
            'access_type' => 'standard',
            'description' => 'Can write new posts, add media and edit their own posts, but not others',
        ],
        'contributor' => [
            'id' => 'contributor',
            'label' => 'Contributer',
            'role_group' => 'content',
            'access_type' => 'standard',
            'description' => 'Can write new posts and edit them, but can\'t publish them',
        ],
        'subscriber' => [
            'id' => 'subscriber',
            'label' => 'Subscriber',
            'role_group' => 'content',
            'access_type' => 'minimal',
            'description' => 'So people can have an account to post comments',
        ],
    ];

    protected $configurations = [
        'image-debug' => [
            'name' => 'image-debug',
            'type' => 'bool',
            'persistent' => false,
            'default' => false,
            'validations' => 'boolean',
        ],
        'image-pullPolicy' => [
            'name' => 'image-pullPolicy',
            'type' => 'string',
            'persistent' => false,
            'default' => 'IfNotPresent',
            'validations' => 'nullable|in:Always,IfNotPresent',
        ],
        'replicaCount' => [
            'name' => 'replicaCount',
            'type' => 'int',
            'persistent' => false,
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
        'persistence-accessModes' => [
            'name' => 'persistence-accessModes',
            'type' => 'json',
            'persistent' => true,
            'validations' => 'nullable|json',
            'default' => [
                'ReadWriteOnce',
            ],
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
        'updateStrategy-rollingUpdate' => [
            'name' => 'updateStrategy-rollingupdate',
            'type' => 'string',
            'default' => null,
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'updateStrategy-type' => [
            'name' => 'updateStrategy-type',
            'type' => 'string',
            'default' => 'RollingUpdate',
            'persistent' => false,
            'validations' => 'in:RollingUpdate,Recreate',
        ],
        'customReadinessProbe-failureThreshold' => [
            'name' => 'customReadinessProbe-failureThreshold',
            'type' => 'int',
            'default' => 6,
            'persistent' => false,
            'validations' => 'nullable|integer',
        ],
        'customReadinessProbe-initialDelaySeconds' => [
            'name' => 'customReadinessProbe-initialDelaySeconds',
            'type' => 'int',
            'default' => 10,
            'persistent' => false,
            'validations' => 'nullable|integer',
        ],
        'customReadinessProbe-periodSeconds' => [
            'name' => 'customReadinessProbe-periodSeconds',
            'type' => 'int',
            'default' => 10,
            'persistent' => false,
            'validations' => 'nullable|integer',
        ],
        'customReadinessProbe-successThreshold' => [
            'name' => 'customReadinessProbe-failureThreshold',
            'type' => 'int',
            'default' => 1,
            'persistent' => false,
            'validations' => 'nullable|integer',
        ],
        'customReadinessProbe-timeoutSeconds' => [
            'name' => 'customReadinessProbe-timeoutSeconds',
            'type' => 'int',
            'default' => 5,
            'persistent' => false,
            'validations' => 'nullable|integer',
        ],
        'mariadb' => [
            'name' => 'mariadb',
            'type' => 'json',
            'persistent' => true,
            'validations' => 'json',
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
        'resources-requests-cpu' => [
            'name' => 'resources-requests-cpu',
            'type' => 'string',
            'default' => '500m',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'resources-requests-memory' => [
            'name' => 'resources-requests-memory',
            'type' => 'string',
            'default' => '500Mi',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'resources-limits-cpu' => [
            'name' => 'resources-limits-cpu',
            'type' => 'string',
            'default' => '1250m',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'resources-limits-memory' => [
            'name' => 'resources-limits-memory',
            'type' => 'string',
            'default' => '1Gi',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'wordpress-email' => [
            'name' => 'wordpress-email',
            'type' => 'string',
            'default' => '',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'wordpress-firstname' => [
            'name' => 'wordpress-firstname',
            'type' => 'string',
            'default' => 'Wordpress',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'wordpress-lastname' => [
            'name' => 'wordpress-lastname',
            'type' => 'string',
            'default' => 'Support',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'wordpress-plugins' => [
            'name' => 'wordpress-plugins',
            'type' => 'string',
            'default' => '',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'wordpress-username' => [
            'name' => 'wordpress-username',
            'type' => 'string',
            'default' => 'support',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'ingress-enabled' => [
            'name' => 'ingress-enabled',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'nullable|boolean',
        ],
        'ingress-tls' => [
            'name' => 'ingress-tls',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'nullable|boolean',
        ],
        'ingress-annotation-cluster_issuer' => [
            'name' => 'ingress-annotation-cluster_issuer',
            'type' => 'string',
            'default' => 'letsencrypt-production',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'ingress-annotation-traefik_middlewares' => [
            'name' => 'ingress-annotation-traefik_middlewares',
            'type' => 'string',
            'default' => 'https-redirect@kubernetescrd',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'ldap-starttls' => [
            'name' => 'ldap-starttls',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'nullable|boolean',
        ],
        'enable-sso' => [
            'name' => 'enable-sso',
            'type' => 'bool',
            'default' => false,
            'persistent' => false,
            'validations' => 'nullable|boolean',
        ],
        'oidc-login-type' => [
            'name' => 'oidc-login-type',
            'type' => 'string',
            'default' => 'button',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
        'oidc-client-scope' => [
            'name' => 'oidc-client-scope',
            'type' => 'string',
            'default' => 'email profile openid offline_access',
            'persistent' => false,
            'validations' => 'nullable|string',
        ],
    ];
}
