<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\Support;

use Illuminate\Support\Str;

/**
 * Builds the ConfigMap/Secret/Job manifests for running a single helm
 * upgrade/uninstall as an in-cluster Job, under the per-namespace
 * kumulicp-helm-installer identity rather than the long-lived
 * kumulicp-deployer used for everything else -- see HelmInstaller.
 *
 * Pure manifest builder: no I/O, no kubectl/Process calls.
 */
class HelmInstallJob
{
    public readonly string $jobName;

    /**
     * @param  array<int, string>  $helmSubcommand  e.g. ['upgrade', '--install', $release, $chartRef, ..., '-f', '/values/values.yaml', '--wait', '--timeout', '720s']
     * @param  array<string, string>|null  $secretEnv  env var name => value, materialized as a Secret and injected via envFrom (e.g. OCI_REGISTRY_HOST/OCI_USERNAME/OCI_PASSWORD or HELM_REPO_USERNAME/HELM_REPO_PASSWORD)
     */
    public function __construct(
        private string $namespace,
        private string $releaseName,
        private array $helmSubcommand,
        private ?string $valuesYaml = null,
        private ?array $secretEnv = null,
    ) {
        $this->jobName = 'helm-install-'.Str::lower(Str::slug($releaseName)).'-'.Str::lower(Str::random(8));
    }

    public function configMapName(): string
    {
        return "helm-values-{$this->jobName}";
    }

    public function secretName(): string
    {
        return "helm-creds-{$this->jobName}";
    }

    public function labelSelector(): string
    {
        return "job-name={$this->jobName}";
    }

    /**
     * $ownerJobUid, when given, makes Kubernetes' garbage collector delete
     * this ConfigMap once the owning Job is reaped (ttlSecondsAfterFinished)
     * -- nothing in PHP has to run to completion for cleanup to happen. Only
     * known after the Job itself has been created, so the first apply omits
     * it and a second apply (see HelmInstaller::create()) adds it.
     */
    public function configMapManifest(?string $ownerJobUid = null): ?array
    {
        if ($this->valuesYaml === null) {
            return null;
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => array_filter([
                'name' => $this->configMapName(),
                'namespace' => $this->namespace,
                'labels' => $this->labels(),
                'ownerReferences' => $this->ownerReferences($ownerJobUid) ?: null,
            ]),
            'data' => [
                'values.yaml' => $this->valuesYaml,
            ],
        ];
    }

    public function secretManifest(?string $ownerJobUid = null): ?array
    {
        if (empty($this->secretEnv)) {
            return null;
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'type' => 'Opaque',
            'metadata' => array_filter([
                'name' => $this->secretName(),
                'namespace' => $this->namespace,
                'labels' => $this->labels(),
                'ownerReferences' => $this->ownerReferences($ownerJobUid) ?: null,
            ]),
            'stringData' => $this->secretEnv,
        ];
    }

    private function ownerReferences(?string $ownerJobUid): array
    {
        if (! $ownerJobUid) {
            return [];
        }

        return [[
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'name' => $this->jobName,
            'uid' => $ownerJobUid,
            'blockOwnerDeletion' => false,
            'controller' => false,
        ]];
    }

    // Runs on the stock alpine/helm image (no custom image to build/push)
    // via `sh -c`, so credential handling lives here instead of a baked-in
    // entrypoint. OCI_REGISTRY_HOST/OCI_USERNAME/OCI_PASSWORD and
    // HELM_REPO_USERNAME/HELM_REPO_PASSWORD arrive as env vars sourced from
    // a Secret (see secretManifest()), never as args, so they don't show up
    // in `kubectl describe pod`. `set -e` so a failed registry login aborts
    // instead of silently running helm without it.
    private const ENTRYPOINT_SCRIPT = <<<'SH'
        set -e
        if [ -n "$OCI_REGISTRY_HOST" ]; then
            echo "$OCI_PASSWORD" | helm registry login "$OCI_REGISTRY_HOST" --username "$OCI_USERNAME" --password-stdin
        fi
        if [ -n "$HELM_REPO_USERNAME" ]; then
            set -- "$@" --username "$HELM_REPO_USERNAME" --password "$HELM_REPO_PASSWORD"
        fi
        exec helm "$@"
        SH;

    public function jobManifest(): array
    {
        $container = [
            'name' => 'helm',
            'image' => config('services.helm_runner.image'),
            'imagePullPolicy' => 'IfNotPresent',
            'command' => ['/bin/sh', '-c'],
            'args' => [self::ENTRYPOINT_SCRIPT, 'sh', ...$this->helmSubcommand],
        ];

        if (! empty($this->secretEnv)) {
            $container['envFrom'] = [
                ['secretRef' => ['name' => $this->secretName()]],
            ];
        }

        $volumes = [];

        if ($this->valuesYaml !== null) {
            $container['volumeMounts'] = [[
                'name' => 'values',
                'mountPath' => '/values',
                'readOnly' => true,
            ]];

            $volumes[] = [
                'name' => 'values',
                'configMap' => ['name' => $this->configMapName()],
            ];
        }

        return [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => [
                'name' => $this->jobName,
                'namespace' => $this->namespace,
                'labels' => $this->labels(),
            ],
            'spec' => [
                'backoffLimit' => 0,
                'ttlSecondsAfterFinished' => 3600,
                'activeDeadlineSeconds' => 780,
                'template' => [
                    'metadata' => [
                        'labels' => $this->labels(),
                    ],
                    'spec' => array_filter([
                        'serviceAccountName' => 'kumulicp-helm-installer',
                        'restartPolicy' => 'Never',
                        'terminationGracePeriodSeconds' => 30,
                        'containers' => [$container],
                        'volumes' => $volumes ?: null,
                    ]),
                ],
            ],
        ];
    }

    private function labels(): array
    {
        return [
            'app.kubernetes.io/managed-by' => 'kumulicp-helm-installer',
            'kumulicp.io/release' => Str::slug($this->releaseName),
        ];
    }
}
