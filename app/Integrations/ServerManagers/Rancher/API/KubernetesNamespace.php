<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\Integrations\ServerManagers\Kubernetes\API\KubernetesNamespace as BaseKubernetesNamespace;
use App\Integrations\ServerManagers\Rancher\Support\UsesRancher;

/**
 * A namespace in a Rancher-managed cluster belongs to a Rancher project.
 */
class KubernetesNamespace extends BaseKubernetesNamespace
{
    use UsesRancher;

    protected function state(array $namespace): int
    {
        return $this->rancherState($namespace);
    }

    protected function manifest(string $namespace): array
    {
        $project_id = $this->server()->setting('project_id');

        return array_replace_recursive(parent::manifest($namespace), [
            'metadata' => [
                'annotations' => ['field.cattle.io/projectId' => 'local:'.$project_id],
                'labels' => ['field.cattle.io/projectId' => $project_id],
            ],
        ]) + [
            'disableOpenApiValidation' => false,
            'name' => $namespace,
        ];
    }
}
