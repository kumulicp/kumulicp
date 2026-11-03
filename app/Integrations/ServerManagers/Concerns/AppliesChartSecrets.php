<?php

namespace App\Integrations\ServerManagers\Concerns;

use App\Integrations\ServerManagers\Kubernetes\Support\KubernetesApiClient;
use App\Integrations\ServerManagers\Rancher\Charts\ChartSecrets;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a chart's or job's collected secrets as a Kubernetes Secret through
 * the Kubernetes API. Shared by the drivers that can reach that API (directly,
 * or through Rancher's cluster proxy). Needs $this->organization and
 * clusterApi(): a Secret is always a plain Kubernetes object, whatever else
 * the driver manages its resources through.
 */
trait AppliesChartSecrets
{
    abstract public function clusterApi(): KubernetesApiClient;

    // Applies a chart's collected secrets as a standard Secret. Must run before
    // the workload that references it is created. No-op for an empty store.
    // $ownerJob (name, uid) is for job stores, so the Secret is garbage-collected
    // with its Job.
    public function applyStore(ChartSecrets $store, ?array $ownerJob = null): array
    {
        if ($store->isEmpty()) {
            return ['status' => 'success', 'response' => null];
        }

        $manifest = $store->manifest($ownerJob['uid'] ?? null, $ownerJob['name'] ?? null);
        $result = $this->clusterApi()->apply($manifest, $store->namespace());

        // Only the name is logged: the manifest and its values must never reach logs.
        Log::info(__('messages.api.rancher.log.chart_secret_applied', ['namespace' => $store->namespace(), 'name' => $store->name()]), ['organization_id' => $this->organization->id]);

        return [
            'status' => $result['success'] ? 'success' : 'failed',
            'response' => $result['success'] ? null : $result['error'],
        ];
    }

    public function removeStore(ChartSecrets $store): array
    {
        $result = $this->clusterApi()->delete('v1', 'Secret', $store->name(), $store->namespace());

        Log::info(__('messages.api.rancher.log.chart_secret_deleted', ['namespace' => $store->namespace(), 'name' => $store->name()]), ['organization_id' => $this->organization->id]);

        return ['status' => $result['success'] ? 'success' : 'failed', 'response' => $result['success'] ? null : $result['error']];
    }
}
