<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\API;

use App\Integrations\ServerManagers\HelmKubernetes\Kubernetes;
use App\Integrations\ServerManagers\Rancher\Charts\Middleware\MiddlewareChart;
use Illuminate\Support\Facades\Log;

/**
 * Manages Traefik's Middleware CRD (domain redirects) via kubectl. Only
 * runs when the server's k8s_ingress_class is explicitly set to 'traefik' —
 * this CRD isn't generic Kubernetes, it only exists once Traefik is
 * installed as the ingress controller. If the field is blank or set to
 * anything else (e.g. 'nginx'), redirect-rule management is skipped
 * entirely rather than assuming the CRD is present.
 */
class Middleware extends Kubernetes
{
    public function enabled(): bool
    {
        return $this->server()->k8s_ingress_class === 'traefik';
    }

    public function create(MiddlewareChart $chart)
    {
        if (! $this->enabled()) {
            return ['status' => 'success', 'response' => null];
        }

        $namespace = $this->organization->slug;
        $result = $this->kubectl()->apply($chart->values(), $namespace);

        Log::info(__('messages.api.rancher.log.middleware_created', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return [
            'status' => $result['success'] ? 'success' : 'failed',
            'response' => $result['success'] ? json_decode($result['output'], true) : $result['error'],
        ];
    }

    public function update(MiddlewareChart $chart)
    {
        return $this->create($chart);
    }

    public function remove(MiddlewareChart $chart)
    {
        if (! $this->enabled()) {
            return ['status' => 'success', 'response' => null];
        }

        $namespace = $this->organization->slug;
        $result = $this->kubectl()->delete('middleware.traefik.io', $chart->name, $namespace);

        Log::info(__('messages.api.rancher.log.middleware_deleted', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return ['status' => 'success', 'response' => $result['output']];
    }

    public function isActive(MiddlewareChart $chart): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $namespace = $this->organization->slug;
        $result = $this->kubectl()->get('middleware.traefik.io', $chart->name, $namespace);

        return $result['success'] ? 1 : 0;
    }
}
