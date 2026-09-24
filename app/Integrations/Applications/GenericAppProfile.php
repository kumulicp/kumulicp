<?php

namespace App\Integrations\Applications;

use App\Integrations\ServerManagers\Rancher\Charts\GenericChart;

class GenericAppProfile extends AppProfile
{
    protected $name = 'generic';

    protected $activation_type = 'chart';

    protected $compatibility = ['openid', 'ldap', 'shareable', 'helm_chart'];

    protected $helm_chart = GenericChart::class;

    // 'helm_chart_repo' is intentionally left unset -- the chart's source
    // lives in this repo under helm/generic-app but still needs to be
    // published to a Helm repo or OCI registry reachable from target
    // clusters before a version can point its "Helm Repo" field at it.
    // See helm/generic-app/README.md.
    protected $recommendations = [
        'helm_chart_name' => 'generic-app',
        'helm_chart_version' => '0.1.0',
    ];

    protected $configurations = [
        'image-pullPolicy' => [
            'name' => 'image-pullPolicy',
            'type' => 'string',
            'persistent' => false,
            'default' => 'IfNotPresent',
            'validations' => 'in:IfNotPresent,Always,Never',
        ],
        'replicaCount' => [
            'name' => 'replicaCount',
            'type' => 'int',
            'persistent' => false,
            'default' => 1,
            'validations' => 'integer|required',
        ],
        'env' => [
            'name' => 'env',
            'type' => 'yaml',
            'persistent' => true,
            'default' => [],
            'validations' => 'nullable|array',
        ],
        'resources-requests-cpu' => [
            'name' => 'resources-requests-cpu',
            'type' => 'string',
            'default' => '250m',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'resources-requests-memory' => [
            'name' => 'resources-requests-memory',
            'type' => 'string',
            'default' => '256Mi',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'resources-limits-cpu' => [
            'name' => 'resources-limits-cpu',
            'type' => 'string',
            'default' => '1000m',
            'persistent' => false,
            'validations' => 'required|string',
        ],
        'resources-limits-memory' => [
            'name' => 'resources-limits-memory',
            'type' => 'string',
            'default' => '512Mi',
            'persistent' => false,
            'validations' => 'required|string',
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
            'default' => '',
            'validations' => 'nullable|string',
        ],
        'persistence-existingClaim' => [
            'name' => 'persistence-existingClaim',
            'type' => 'string',
            'persistent' => true,
            'default' => '',
            'validations' => 'nullable|string',
        ],
        'persistence-accessMode' => [
            'name' => 'persistence-accessMode',
            'type' => 'string',
            'persistent' => true,
            'default' => 'ReadWriteOnce',
            'validations' => 'nullable|in:ReadWriteOnce,ReadWriteMany',
        ],
        'persistence-mountPath' => [
            'name' => 'persistence-mountPath',
            'type' => 'string',
            'persistent' => true,
            'default' => '/data',
            'validations' => 'nullable|string',
        ],
        'ingress-enabled' => [
            'name' => 'ingress-enabled',
            'type' => 'bool',
            'persistent' => false,
            'default' => false,
            'validations' => 'boolean',
        ],
        'ingress-tls' => [
            'name' => 'ingress-tls',
            'type' => 'bool',
            'persistent' => false,
            'default' => false,
            'validations' => 'boolean',
        ],
        'ingress-className' => [
            'name' => 'ingress-className',
            'type' => 'string',
            'persistent' => false,
            'default' => '',
            'validations' => 'nullable|string',
        ],
    ];
}
