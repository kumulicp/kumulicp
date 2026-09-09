<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\API;

use App\Integrations\ServerManagers\HelmKubernetes\Kubernetes;
use App\Integrations\ServerManagers\HelmKubernetes\Support\HelmInstallJob;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Runs a helm upgrade/uninstall as a short-lived Job inside the target
 * cluster (mirroring Rancher's own chart-install mechanism), under the
 * per-namespace kumulicp-helm-installer identity rather than the
 * long-lived kumulicp-deployer used elsewhere in this driver. This is
 * what lets a chart create arbitrary resource kinds (NetworkPolicy, PDB,
 * Role, HPA, ...) without kumulicp-deployer's ClusterRole ever needing to
 * enumerate them -- see docs/k8s-rbac-sample.yaml.
 */
class HelmInstaller extends Kubernetes
{
    /**
     * @param  array<int, string>  $helmSubcommand
     * @param  array<string, string>|null  $secretEnv
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function runAndWait(
        string $namespace,
        string $releaseName,
        array $helmSubcommand,
        ?string $valuesYaml = null,
        ?array $secretEnv = null,
        int $timeoutSeconds = 780,
    ): array {
        $job = new HelmInstallJob($namespace, $releaseName, $helmSubcommand, $valuesYaml, $secretEnv);

        if (! $this->create($job, $namespace)) {
            return [
                'success' => false,
                'output' => '',
                'error' => "Failed to create helm install job for release {$releaseName}",
                'exit_code' => 1,
            ];
        }

        Log::info(__('messages.api.rancher.log.job_created', ['organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        $deadline = now()->addSeconds($timeoutSeconds);
        $status = 'running';

        while (now()->lt($deadline)) {
            $status = $this->status($job->jobName, $namespace);

            if (in_array($status, ['success', 'failed'], true)) {
                break;
            }

            Sleep::for(5)->seconds();
        }

        $output = $this->logs($job->labelSelector(), $namespace);

        $this->cleanup($job, $namespace);

        if ($status !== 'success') {
            $message = $status === 'running'
                ? "helm job {$job->jobName} did not complete within {$timeoutSeconds}s"
                : $output;

            return ['success' => false, 'output' => $output, 'error' => $message, 'exit_code' => 1];
        }

        return ['success' => true, 'output' => $output, 'error' => '', 'exit_code' => 0];
    }

    private function create(HelmInstallJob $job, string $namespace): bool
    {
        if ($manifest = $job->configMapManifest()) {
            if (! $this->kubectl()->apply($manifest, $namespace)['success']) {
                return false;
            }
        }

        if ($manifest = $job->secretManifest()) {
            if (! $this->kubectl()->apply($manifest, $namespace)['success']) {
                return false;
            }
        }

        return $this->kubectl()->apply($job->jobManifest(), $namespace)['success'];
    }

    // Mirrors Job::status()'s exact status.active/succeeded/failed
    // inspection -- kept separate rather than reused because that class
    // is keyed to the fire-and-poll-later app-job flow (different
    // lifecycle semantics), not this synchronous wait loop.
    private function status(string $jobName, string $namespace): string
    {
        $result = $this->kubectl()->get('job', $jobName, $namespace);

        if (! $result['success']) {
            return 'failed';
        }

        $data = json_decode($result['output'], true);

        if (Arr::get($data, 'status.active') == 1) {
            return 'running';
        }

        if (Arr::get($data, 'status.succeeded') == 1) {
            return 'success';
        }

        if (Arr::get($data, 'status.failed') >= 1) {
            return 'failed';
        }

        return 'running';
    }

    private function logs(string $labelSelector, string $namespace): string
    {
        $result = $this->kubectl()->logs($labelSelector, $namespace);

        return trim($result['output'] ?: $result['error']);
    }

    // Best-effort -- the Job itself is left for ttlSecondsAfterFinished to
    // reap so `kubectl describe job` stays inspectable after a failure.
    private function cleanup(HelmInstallJob $job, string $namespace): void
    {
        if ($job->configMapManifest()) {
            $this->kubectl()->delete('configmap', $job->configMapName(), $namespace);
        }

        if ($job->secretManifest()) {
            $this->kubectl()->delete('secret', $job->secretName(), $namespace);
        }
    }
}
