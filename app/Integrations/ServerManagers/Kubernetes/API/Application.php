<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\API;

use App\AppInstance;
use App\Integrations\ServerManagers\HelmKubernetes\Kubernetes;
use App\Integrations\ServerManagers\Rancher\Charts\HelmChart;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * Installs/upgrades/removes app releases. install/upgrade/uninstall start
 * an in-cluster Job (see HelmInstaller) under the per-namespace
 * kumulicp-helm-installer identity, since those are the operations that
 * touch whatever arbitrary resource kinds a chart's subcharts create --
 * HelmInstaller returns as soon as the Job is accepted, it does not wait
 * for the install itself to finish (see its class docblock for why).
 * Actual completion is tracked separately via isActive(), on the normal
 * task-completion polling schedule (ApplicationUpgrade::complete() etc).
 * Read-only/Helm's-own-bookkeeping operations (retrieve, isActive,
 * deleteStuckReleaseSecrets) read Helm's release Secrets straight from the
 * Kubernetes API under kumulicp-deployer (see HelmReleases), so neither a
 * `helm` nor a `kubectl` binary is needed where this app runs. Reuses the
 * same Chart value-array builders Rancher uses (NextcloudChart,
 * WordpressChart, CiviCRMStandaloneChart, ...).
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

        [$chart_ref, $repo_args, $secret_env] = $this->chartReference($app_instance);

        $values_yaml = Yaml::dump($chart->valuesWithAdditionalConfigs(), 10);

        $subcommand = array_merge(['upgrade', '--install', $release_name, $chart_ref], $repo_args);

        if ($version = $app_instance->version->setting('chart_version')) {
            array_push($subcommand, '--version', (string) $version);
        }

        array_push($subcommand, '-f', '/values/values.yaml', '--wait', '--timeout', '720s');

        $result = $this->helmInstaller()->create($namespace, $release_name, $subcommand, $values_yaml, $secret_env);

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

        $result = $this->helmReleases()->values($release_name, $namespace);

        Log::info(__('messages.api.rancher.log.app_retrieved', ['app' => $app_instance->name, 'organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        return [
            'status' => $result['success'] ? 'success' : 'failed',
            'response' => $result['success'] ? $result['values'] : $result['error'],
        ];
    }

    public function remove(AppInstance $app_instance, HelmChart $chart)
    {
        $namespace = $chart->namespace();
        $release_name = $chart->chartName();

        $result = $this->helmInstaller()->create($namespace, $release_name, ['uninstall', $release_name, '--ignore-not-found']);

        if (! $result['success']) {
            return ['status' => 'failed', 'response' => $result['error']];
        }

        Log::info(__('messages.api.rancher.log.app_deleted', ['app' => $app_instance->name, 'organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        return ['status' => 'success', 'response' => $result['output']];
    }

    public function isActive(AppInstance $app_instance, HelmChart $chart): int
    {
        $namespace = $chart->namespace();
        $release_name = $chart->chartName();

        $status = $this->helmReleases()->status($release_name, $namespace);

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

        foreach ($this->helmReleases()->pendingSecretNames($release_name, $namespace) as $name) {
            $this->api()->delete('v1', 'Secret', $name, $namespace);
        }
    }

    // AppVersion::setting('helm_repo_name') holds the chart repository
    // location for this driver — a plain https URL, or an oci:// reference.
    // (For Rancher it instead holds a pre-registered ClusterRepo name; both
    // interpretations are compatible since it's a per-version, admin-set field.)
    //
    // Returns [$chart_ref, $repo_args, $secret_env]. $secret_env is a
    // name => value map materialized as a Kubernetes Secret and injected
    // into the install Job as env vars (see HelmInstallJob) -- credentials
    // never go into $repo_args/Job args, since those are visible via
    // `kubectl describe pod`. The helm-runner image's entrypoint reads
    // OCI_REGISTRY_HOST/OCI_USERNAME/OCI_PASSWORD to `helm registry login`
    // before the main command, or HELM_REPO_USERNAME/HELM_REPO_PASSWORD to
    // append --username/--password for classic repos.
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

            $secret_env = $secret ? [
                'OCI_REGISTRY_HOST' => $host,
                'OCI_USERNAME' => $secret->username,
                'OCI_PASSWORD' => $secret->password,
            ] : null;

            return [$chart_ref, [], $secret_env];
        }

        if ($repo) {
            $secret_env = $secret ? [
                'HELM_REPO_USERNAME' => $secret->username,
                'HELM_REPO_PASSWORD' => $secret->password,
            ] : null;

            return [$chart_name, ['--repo', $repo], $secret_env];
        }

        return [$chart_name, [], null];
    }
}
