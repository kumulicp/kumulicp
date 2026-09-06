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

        [$chart_ref, $repo_args, $oci_registry_host] = $this->chartReference($app_instance);

        $values_yaml = Yaml::dump($chart->valuesWithAdditionalConfigs(), 10);

        $subcommand = array_merge(['upgrade', '--install', $release_name, $chart_ref], $repo_args);

        if ($version = $app_instance->version->setting('chart_version')) {
            array_push($subcommand, '--version', (string) $version);
        }

        array_push($subcommand, '-f', '-', '--wait', '--timeout', '720s');

        $secret = $app_instance->version->requiresHelmRepoAuth() ? $app_instance->version->helmRepoSecret : null;

        if ($oci_registry_host && $secret) {
            $result = $this->helm()->runWithOciLogin($subcommand, $namespace, $values_yaml, $oci_registry_host, $secret->username, $secret->password);
        } else {
            $result = $this->helm()->run($subcommand, $namespace, $values_yaml);
        }

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

    /**
     * Deletes the release's Helm secret revision(s) currently stuck in a
     * pending-* state -- e.g. because the process running `helm upgrade
     * --wait` was killed (container restart) before it could write the
     * final deployed/failed status back. Helm refuses any further
     * operation on a release while its latest revision reads pending-*, so
     * without this it's stuck forever; deleting just that revision's
     * secret (not the underlying k8s resources) makes the release read as
     * "not found" again, which installOrUpgrade() already treats as a
     * fresh install to fall back to.
     */
    public function deleteStuckReleaseSecrets(HelmChart $chart): void
    {
        $namespace = $chart->namespace();
        $release_name = $chart->chartName();

        $result = $this->kubectl()->run(['get', 'secret', '-l', "owner=helm,name={$release_name}", '-o', 'json'], $namespace);

        if (! $result['success']) {
            return;
        }

        $secrets = json_decode($result['output'], true)['items'] ?? [];

        foreach ($secrets as $secret) {
            $status = $secret['metadata']['labels']['status'] ?? '';
            $name = $secret['metadata']['name'] ?? null;

            if ($name && str_starts_with($status, 'pending-')) {
                $this->kubectl()->delete('secret', $name, $namespace);
            }
        }
    }

    // AppVersion::setting('helm_repo_name') holds the chart repository
    // location for this driver — a plain https URL, or an oci:// reference.
    // (For Rancher it instead holds a pre-registered ClusterRepo name; both
    // interpretations are compatible since it's a per-version, admin-set field.)
    //
    // Returns [$chart_ref, $repo_args, $oci_registry_host]. OCI auth needs a
    // `helm registry login` step first (see HelmCli::runWithOciLogin()), so
    // the registry host is returned separately rather than folded into
    // $repo_args like the classic-repo --username/--password flags are.
    private function chartReference(AppInstance $app_instance): array
    {
        $chart_name = $app_instance->version->setting('chart_name');
        $repo = $app_instance->version->setting('helm_repo_name');
        $secret = $app_instance->version->requiresHelmRepoAuth() ? $app_instance->version->helmRepoSecret : null;

        if ($repo && str_starts_with($repo, 'oci://')) {
            $chart_ref = rtrim($repo, '/').'/'.$chart_name;
            $host = parse_url($repo, PHP_URL_HOST) ?: '';
            if ($port = parse_url($repo, PHP_URL_PORT)) {
                $host .= ":{$port}";
            }

            return [$chart_ref, [], $secret ? $host : null];
        }

        if ($repo) {
            $repo_args = ['--repo', $repo];
            if ($secret) {
                array_push($repo_args, '--username', $secret->username, '--password', $secret->password);
            }

            return [$chart_name, $repo_args, null];
        }

        return [$chart_name, [], null];
    }
}
