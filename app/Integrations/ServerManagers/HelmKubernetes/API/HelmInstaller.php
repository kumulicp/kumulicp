<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\API;

use App\Integrations\ServerManagers\HelmKubernetes\Kubernetes;
use App\Integrations\ServerManagers\HelmKubernetes\Support\HelmInstallJob;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Starts a helm upgrade/uninstall as a short-lived Job inside the target
 * cluster (mirroring Rancher's own chart-install mechanism), under the
 * per-namespace kumulicp-helm-installer identity rather than the
 * long-lived kumulicp-deployer used elsewhere in this driver. This is
 * what lets a chart create arbitrary resource kinds (NetworkPolicy, PDB,
 * Role, HPA, ...) without kumulicp-deployer's ClusterRole ever needing to
 * enumerate them -- see docs/k8s-rbac-sample.yaml.
 *
 * Deliberately does not wait for the Job to finish: install/upgrade can
 * legitimately take minutes (helm's own --wait), and this used to block
 * the calling queue worker for that whole duration. Actual completion is
 * already tracked asynchronously elsewhere -- Application::isActive()
 * reads the release's own Helm bookkeeping (independent of this Job), and
 * ApplicationUpgrade::complete()/ApplicationActivate::complete() poll it
 * on the normal task-completion schedule. create() only needs to confirm
 * the Job was accepted.
 */
class HelmInstaller extends Kubernetes
{
    /**
     * @param  array<int, string>  $helmSubcommand
     * @param  array<string, string>|null  $secretEnv
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function create(
        string $namespace,
        string $releaseName,
        array $helmSubcommand,
        ?string $valuesYaml = null,
        ?array $secretEnv = null,
    ): array {
        $job = new HelmInstallJob($namespace, $releaseName, $helmSubcommand, $valuesYaml, $secretEnv);

        if ($manifest = $job->configMapManifest()) {
            $result = $this->kubectl()->apply($manifest, $namespace);

            if (! $result['success']) {
                return $this->failure("Failed to create values ConfigMap for release {$releaseName}: {$result['error']}");
            }
        }

        if ($manifest = $job->secretManifest()) {
            $result = $this->kubectl()->apply($manifest, $namespace);

            if (! $result['success']) {
                $this->deleteSupportingResources($job, $namespace);

                return $this->failure("Failed to create credentials Secret for release {$releaseName}: {$result['error']}");
            }
        }

        $job_result = $this->kubectl()->apply($job->jobManifest(), $namespace);

        if (! $job_result['success']) {
            $this->deleteSupportingResources($job, $namespace);

            return $this->failure($job_result['error'] ?: "Failed to create install job for release {$releaseName}");
        }

        $this->attachOwnerReferences($job, $namespace, $job_result);

        Log::info(__('messages.api.rancher.log.job_created', ['organization' => $this->organization->name]), ['organization_id' => $this->organization->id]);

        return [
            'success' => true,
            'output' => "Started {$job->jobName} for release {$releaseName}",
            'error' => '',
            'exit_code' => 0,
        ];
    }

    private function failure(string $message): array
    {
        return ['success' => false, 'output' => '', 'error' => $message, 'exit_code' => 1];
    }

    // Re-applies the ConfigMap/Secret with an ownerReference to the now-
    // created Job, so Kubernetes' garbage collector deletes them once the
    // Job itself is reaped (ttlSecondsAfterFinished) -- cleanup no longer
    // depends on any PHP process running to completion. The Job has to
    // exist first to get its uid, so this always happens as a follow-up
    // apply rather than being included in the original manifest.
    private function attachOwnerReferences(HelmInstallJob $job, string $namespace, array $job_result): void
    {
        $uid = Arr::get(json_decode($job_result['output'], true) ?? [], 'metadata.uid');

        if (! $uid) {
            return;
        }

        if ($manifest = $job->configMapManifest($uid)) {
            $this->kubectl()->apply($manifest, $namespace);
        }

        if ($manifest = $job->secretManifest($uid)) {
            $this->kubectl()->apply($manifest, $namespace);
        }
    }

    // Only reached when the Job itself never got created, so there's no
    // owner to eventually garbage-collect these -- clean them up directly.
    private function deleteSupportingResources(HelmInstallJob $job, string $namespace): void
    {
        if ($job->configMapManifest()) {
            $this->kubectl()->delete('configmap', $job->configMapName(), $namespace);
        }

        if ($job->secretManifest()) {
            $this->kubectl()->delete('secret', $job->secretName(), $namespace);
        }
    }
}
