<?php

namespace App\Integrations\Applications;

use App\Integrations\ServerManagers\Rancher\Charts\GenericChart;

class GenericAppProfile extends AppProfile
{
    protected $name = 'generic';

    protected $activation_type = 'chart';

    protected $compatibility = ['openid', 'ldap', 'shareable', 'helm_chart'];

    protected $helm_chart = GenericChart::class;

    // The "generic" chart from https://github.com/helmforgedev/charts --
    // published both as a classic Helm repo and as an OCI registry. See
    // GenericChart for which subset of its schema this profile drives.
    protected $recommendations = [
        'helm_chart_repo' => 'https://repo.helmforge.dev',
        'helm_chart_name' => 'generic',
        'helm_chart_version' => '3.1.5',
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
        // Same shape as `env`, but delivered through a Secret (envFrom) so the
        // values stay out of the pod spec.
        'secretEnv' => [
            'name' => 'secretEnv',
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
        // One PVC per `persistence-<index>-*` group; see
        // resources/js/services/arrayConfigs.ts for how these are grouped and
        // cloned on the plan configurations page. `name` and `mountPath` must
        // be unique across items, so edit them on any item you add.
        'persistence-0-name' => [
            'name' => 'persistence-0-name',
            'type' => 'string',
            'persistent' => true,
            'default' => 'data',
            'validations' => 'nullable|string',
        ],
        'persistence-0-mountPath' => [
            'name' => 'persistence-0-mountPath',
            'type' => 'string',
            'persistent' => true,
            'default' => '/data',
            'validations' => 'nullable|string',
        ],
        // Size in Gi. Empty uses the instance's app storage.
        'persistence-0-size' => [
            'name' => 'persistence-0-size',
            'type' => 'int',
            'persistent' => true,
            'default' => null,
            'validations' => 'nullable|integer|min:1',
        ],
        'persistence-0-accessMode' => [
            'name' => 'persistence-0-accessMode',
            'type' => 'enum',
            'options' => ['ReadWriteOnce', 'ReadWriteMany'],
            'persistent' => true,
            'default' => 'ReadWriteOnce',
            'validations' => 'nullable|in:ReadWriteOnce,ReadWriteMany',
        ],
        'persistence-0-storageClass' => [
            'name' => 'persistence-0-storageClass',
            'type' => 'string',
            'persistent' => true,
            'default' => '',
            'validations' => 'nullable|string',
        ],
        'persistence-0-existingClaim' => [
            'name' => 'persistence-0-existingClaim',
            'type' => 'string',
            'persistent' => true,
            'default' => '',
            'validations' => 'nullable|string',
        ],
        // HTTP path (e.g. /health) for the readiness and startup probes on the
        // app port. Empty disables probing.
        'probe-path' => [
            'name' => 'probe-path',
            'type' => 'string',
            'persistent' => false,
            'default' => '',
            'validations' => 'nullable|string|starts_with:/',
        ],
        // Empty (or 0) leaves the user/group to the image. fsGroup makes
        // mounted volumes writable by non-root images.
        'security-runAsUser' => [
            'name' => 'security-runAsUser',
            'type' => 'int',
            'persistent' => false,
            'default' => null,
            'validations' => 'nullable|integer|min:0',
        ],
        'security-fsGroup' => [
            'name' => 'security-fsGroup',
            'type' => 'int',
            'persistent' => false,
            'default' => null,
            'validations' => 'nullable|integer|min:0',
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
            'default' => 'traefik',
            'validations' => 'nullable|string',
        ],
        'ingress-annotations' => [
            'name' => 'ingress-annotations',
            'type' => 'yaml',
            'persistent' => false,
            'default' => [],
            'validations' => 'nullable|array',
        ],
    ];
}
