<?php

namespace App\Integrations\ServerManagers\Rancher\Charts;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Collects every secret a chart (or job) needs into one Kubernetes Secret, so
 * credentials are referenced from the workload via secretKeyRef/existingSecret
 * instead of being written into Helm values or container env as plaintext.
 *
 * Pure value object: no I/O. The server-manager driver is responsible for
 * applying manifest() to the cluster before the workload that references it
 * is created (see HelmKubernetes\API\Secret::applyStore()).
 *
 * Usage inside a chart's values():
 *
 *     $secrets = $this->secrets();
 *     $secrets->set('db-password', $this->app_instance->dbPassword());
 *
 *     'existingSecret' => $secrets->name(),             // chart-level secret ref
 *     'env' => [$secrets->envVar('DB_PASSWORD', 'db-password')],
 */
class ChartSecrets
{
    // Secret data keys are limited to alphanumerics, '-', '_' and '.'.
    private const KEY_PATTERN = '/^[-._a-zA-Z0-9]+$/';

    /** @var array<string, string> */
    private array $data = [];

    public function __construct(
        private string $namespace,
        private string $name,
        private ?string $release = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    // A Secret is only visible to workloads in its own namespace, so a driver
    // that runs a workload somewhere other than the chart's default namespace
    // (e.g. a shared-app child's job running in the hub's) retargets the store.
    public function setNamespace(string $namespace): static
    {
        $this->namespace = $namespace;

        return $this;
    }

    // Stores $value under $key. null is stored as '' so a secretKeyRef to the
    // key never dangles (a missing required key stops the pod from starting).
    public function set(string $key, string|int|float|null $value): static
    {
        if (! preg_match(self::KEY_PATTERN, $key)) {
            throw new InvalidArgumentException("Invalid secret key [{$key}]: only alphanumerics, '-', '_' and '.' are allowed.");
        }

        $this->data[$key] = (string) $value;

        return $this;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->data);
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    // The {name, key} pair most charts accept for an existing-secret reference.
    /** @return array{name: string, key: string} */
    public function ref(string $key): array
    {
        $this->assertHas($key);

        return ['name' => $this->name, 'key' => $key];
    }

    // A container env entry that reads $key from this Secret at runtime.
    /** @return array{name: string, valueFrom: array{secretKeyRef: array{name: string, key: string}}} */
    public function envVar(string $envName, string $key): array
    {
        return [
            'name' => $envName,
            'valueFrom' => ['secretKeyRef' => $this->ref($key)],
        ];
    }

    /**
     * Standard Kubernetes Secret manifest. $ownerJobUid, when given, adds an
     * ownerReference to that Job so the cluster garbage-collects the Secret
     * with it (same pattern as HelmInstallJob).
     *
     * @return array<string, mixed>
     */
    public function manifest(?string $ownerJobUid = null, ?string $ownerJobName = null): array
    {
        $data = $this->data;
        ksort($data);

        $labels = ['app.kubernetes.io/managed-by' => 'kumulicp'];
        if ($this->release) {
            $labels['kumulicp.io/release'] = $this->release;
        }

        $metadata = [
            'name' => $this->name,
            'namespace' => $this->namespace,
            'labels' => $labels,
        ];

        if ($ownerJobUid && $ownerJobName) {
            $metadata['ownerReferences'] = [[
                'apiVersion' => 'batch/v1',
                'kind' => 'Job',
                'name' => $ownerJobName,
                'uid' => $ownerJobUid,
                'blockOwnerDeletion' => false,
                'controller' => false,
            ]];
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'type' => 'Opaque',
            'metadata' => $metadata,
            'data' => array_map('base64_encode', $data),
        ];
    }

    // The manifest as YAML, i.e. what `kubectl get secret -o yaml` would show.
    public function toYaml(): string
    {
        return Yaml::dump($this->manifest(), 6, 2);
    }

    private function assertHas(string $key): void
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Secret key [{$key}] has not been set on [{$this->name}]; call set() before referencing it.");
        }
    }
}
