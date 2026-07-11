<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\API;

use App\AppInstance;
use App\Integrations\ServerManagers\HelmKubernetes\Kubernetes;
use App\Integrations\ServerManagers\Rancher\Charts\HelmChart;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * Installs/upgrades/removes app releases via the `helm` CLI directly against
 * the cluster, reusing the same Chart value-array builders Rancher uses
 * (NextcloudChart, WordpressChart, CiviCRMStandaloneChart, ...).
 */
class Application extends Kubernetes
{
    public function create(AppInstance $app_instance, HelmChart $chart)
    {
        return $this->installOrUpgrade($app_instance, $chart);
    }

    public function update(AppInstance $app_instance, HelmChart $chart)
    {
        return $this->installOrUpgrade($app_instance, $chart);
    }

    private function installOrUpgrade(AppInstance $app_instance, HelmChart $chart): array
    {
        $app = $app_instance->application;
        $namespace = $chart->namespace();
        $release_name = $chart->chartName();

        [$chart_ref, $repo_args] = $this->chartReference($app_instance);

        $values_yaml = Yaml::dump($chart->valuesWithAdditionalConfigs(), 10);

        $subcommand = array_merge(['upgrade', '--install', $release_name, $chart_ref], $repo_args);

        if ($version = $app_instance->version->setting('chart_version')) {
            array_push($subcommand, '--version', (string) $version);
        }

        array_push($subcommand, '-f', '-', '--wait', '--timeout', '720s');

        $result = $this->helm()->run($subcommand, $namespace, $values_yaml);

        Log::info(__('messages.api.rancher.log.app_created', ['app' => $app->name, 'organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        if (! $result['success']) {
            return ['status' => 'failed', 'response' => $result['error']];
        }

        return ['status' => 'success', 'response' => $result['output']];
    }

    public function retrieve(AppInstance $app_instance, HelmChart $chart)
    {
        $namespace = $chart->namespace();
        $release_name = $chart->chartName();

        $result = $this->helm()->run(['get', 'values', $release_name, '-o', 'json'], $namespace);

        Log::info(__('messages.api.rancher.log.app_retrieved', ['app' => $app_instance->name, 'organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        return [
            'status' => $result['success'] ? 'success' : 'failed',
            'response' => $result['success'] ? json_decode($result['output'], true) : $result['error'],
        ];
    }

    public function remove(AppInstance $app_instance, HelmChart $chart)
    {
        $namespace = $chart->namespace();
        $release_name = $chart->chartName();

        $result = $this->helm()->run(['uninstall', $release_name, '--ignore-not-found'], $namespace);

        Log::info(__('messages.api.rancher.log.app_deleted', ['app' => $app_instance->name, 'organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        return ['status' => 'success', 'response' => $result['output']];
    }

    public function isActive(AppInstance $app_instance, HelmChart $chart): int
    {
        $namespace = $chart->namespace();
        $release_name = $chart->chartName();

        $result = $this->helm()->run(['status', $release_name, '-o', 'json'], $namespace);

        if (! $result['success']) {
            return 0;
        }

        $data = json_decode($result['output'], true);
        $status = $data['info']['status'] ?? null;

        return match ($status) {
            'deployed' => 1,
            'pending-install', 'pending-upgrade', 'pending-rollback', 'uninstalling' => 2,
            'failed' => 3,
            default => 0,
        };
    }

    // AppVersion::setting('helm_repo_name') holds the chart repository
    // location for this driver — a plain https URL, or an oci:// reference.
    // (For Rancher it instead holds a pre-registered ClusterRepo name; both
    // interpretations are compatible since it's a per-version, admin-set field.)
    private function chartReference(AppInstance $app_instance): array
    {
        $chart_name = $app_instance->version->setting('chart_name');
        $repo = $app_instance->version->setting('helm_repo_name');

        if ($repo && str_starts_with($repo, 'oci://')) {
            return [rtrim($repo, '/').'/'.$chart_name, []];
        }

        if ($repo) {
            return [$chart_name, ['--repo', $repo]];
        }

        return [$chart_name, []];
    }
}
