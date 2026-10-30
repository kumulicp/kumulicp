<?php

namespace App\Integrations\ServerManagers\Rancher\API;

use App\AppInstance;
use App\Integrations\ServerManagers\Kubernetes\API\Application as BaseApplication;
use App\Integrations\ServerManagers\Rancher\Charts\HelmChart;
use App\Integrations\ServerManagers\Rancher\Support\UsesRancher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Installs apps through Rancher's catalog rather than a Helm Job of our own:
 * Rancher runs the install, and reports the release's state on the catalog
 * app. The chart's Secret, which its values reference, is a plain Kubernetes
 * object applied through the cluster proxy before the install (see
 * Chart::secretsDelivered()).
 */
class Application extends BaseApplication
{
    use UsesRancher;

    public function create(AppInstance $app_instance, HelmChart $chart)
    {
        return $this->catalogAction($app_instance, $chart, 'install');
    }

    public function update(AppInstance $app_instance, HelmChart $chart)
    {
        return $this->catalogAction($app_instance, $chart, 'upgrade');
    }

    public function retrieve(AppInstance $app_instance, HelmChart $chart)
    {
        $result = $this->rancher()->get('catalog.cattle.io/v1', 'App', $chart->chartName(), $chart->namespace());

        Log::info(__('messages.api.rancher.log.app_retrieved', ['app' => $app_instance->name, 'organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        return [
            'status' => $result['success'] ? 'success' : 'failed',
            'response' => $result['success'] ? $result['data'] : $result['error'],
        ];
    }

    public function remove(AppInstance $app_instance, HelmChart $chart)
    {
        $path = '/v1/catalog.cattle.io.apps/'.$chart->namespace().'/'.$chart->chartName().'?action=uninstall';

        $result = $this->rancher()->action($path, $chart->buildChart());

        $this->secret()->removeStore($chart->secrets());

        Log::info(__('messages.api.rancher.log.app_deleted', ['app' => $app_instance->name, 'organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        // Already uninstalled is as good as done.
        return [
            'status' => $result['success'] || $result['status'] === 404 ? 'success' : 'failed',
            'response' => $result['data'],
        ];
    }

    // Rancher reports the catalog app's state itself, so it is read from
    // there rather than from Helm's release Secrets and translated into the
    // status names the shared state() understands.
    protected function releaseStatus(string $release_name, string $namespace): ?string
    {
        $result = $this->rancher()->get('catalog.cattle.io/v1', 'App', $release_name, $namespace);

        if (! $result['success']) {
            return null;
        }

        if (Arr::get($result['data'], 'metadata.state.transitioning') === true) {
            return 'pending-upgrade';
        }

        $state = Arr::get($result['data'], 'metadata.state.name');

        return in_array($state, ['deployed', 'failed'], true) ? $state : null;
    }

    // Rancher manages its own releases; there is no Helm Secret of ours to clear.
    public function deleteStuckReleaseSecrets(HelmChart $chart): void {}

    private function catalogAction(AppInstance $app_instance, HelmChart $chart, string $action): array
    {
        $repo_name = $chart->repo_name ?? $app_instance->version->setting('helm_repo_name');

        // buildChart() fills $chart->secrets() as a side effect, so the Secret
        // is only applied after the chart is built, and before the install
        // that references it.
        $data = $chart->buildChart();

        $secret_result = $this->secret()->applyStore($chart->secrets());

        if ($secret_result['status'] !== 'success') {
            return ['status' => 'failed', 'response' => $secret_result['response']];
        }

        $result = $this->rancher()->action("/v1/catalog.cattle.io.clusterrepos/{$repo_name}?action={$action}", $data);

        Log::info(__('messages.api.rancher.log.app_created', ['app' => $app_instance->application->name, 'organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        return [
            'status' => $result['success'] ? 'success' : 'failed',
            'response' => $result['success'] ? $result['data'] : $result['error'],
        ];
    }
}
