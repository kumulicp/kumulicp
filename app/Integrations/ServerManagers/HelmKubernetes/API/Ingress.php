<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\API;

use App\Integrations\ServerManagers\HelmKubernetes\Kubernetes;
use App\Integrations\ServerManagers\Rancher\Charts\Ingress\IngressChart;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class Ingress extends Kubernetes
{
    public function create(IngressChart $chart)
    {
        $namespace = $this->organization->slug;
        $data = $chart->values();

        if (! count(Arr::get($data, 'spec.rules', []))) {
            return ['status' => 'success', 'response' => []];
        }

        $result = $this->api()->apply($data, $namespace);

        Log::info(__('messages.api.rancher.log.ingress_created', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return [
            'status' => $result['success'] ? 'success' : 'failed',
            'response' => $result['success'] ? $result['data'] : $result['error'],
        ];
    }

    // apply is idempotent, so update is the same as create
    public function update(IngressChart $chart)
    {
        return $this->create($chart);
    }

    public function remove(IngressChart $chart)
    {
        $namespace = $this->organization->slug;
        $result = $this->api()->delete('networking.k8s.io/v1', 'Ingress', $chart->name, $namespace);

        Log::info(__('messages.api.rancher.log.ingress_deleted', ['organization' => $namespace]), ['organization_id' => $this->organization->id]);

        return ['status' => 'success', 'response' => $result['data']];
    }

    public function isActive(IngressChart $chart): int
    {
        $namespace = $this->organization->slug;
        $result = $this->api()->get('networking.k8s.io/v1', 'Ingress', $chart->name, $namespace);

        return $result['success'] ? 1 : 0;
    }
}
