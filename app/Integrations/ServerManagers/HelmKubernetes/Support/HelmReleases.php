<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\Support;

/**
 * Read-only view of Helm's own release bookkeeping, replacing `helm status`
 * and `helm get values` so no `helm` binary is needed here. Helm 3's default
 * storage driver keeps one Secret per release revision
 * (`sh.helm.release.v1.<name>.v<revision>`), labelled with
 * owner=helm, name=<release>, version=<revision> and status=<state>, so a
 * release's state is readable straight from the Kubernetes API. Writing
 * (install/upgrade/uninstall) still runs as an in-cluster Job — see
 * HelmInstaller.
 */
class HelmReleases
{
    public function __construct(private KubernetesApiClient $client) {}

    /**
     * Helm's status for the release's latest revision ('deployed', 'failed',
     * 'pending-install', ...), or null if there is no such release (or the
     * cluster couldn't be reached).
     */
    public function status(string $release, string $namespace): ?string
    {
        return $this->latest($release, $namespace, metadata_only: true)['status'] ?? null;
    }

    /**
     * Equivalent of `helm get values <release>`: the user-supplied values
     * of the latest revision.
     *
     * @return array{success: bool, values: mixed, error: string}
     */
    public function values(string $release, string $namespace): array
    {
        $latest = $this->latest($release, $namespace);

        if (! $latest) {
            return ['success' => false, 'values' => null, 'error' => 'release: not found'];
        }

        $decoded = $this->decode($latest['payload']);

        if ($decoded === null) {
            return ['success' => false, 'values' => null, 'error' => "Unable to decode Helm release data for {$release}"];
        }

        return ['success' => true, 'values' => $decoded['config'] ?? null, 'error' => ''];
    }

    /**
     * Names of the release's revision Secrets whose status is pending-* —
     * i.e. whose `helm upgrade`/`install` never recorded an outcome.
     *
     * @return array<int, string>
     */
    public function pendingSecretNames(string $release, string $namespace): array
    {
        $names = [];

        foreach ($this->revisions($release, $namespace, metadata_only: true) as $revision) {
            if (str_starts_with($revision['status'], 'pending-')) {
                $names[] = $revision['secret'];
            }
        }

        return $names;
    }

    /**
     * @return array{secret: string, version: int, status: string, payload: ?string}|null
     */
    private function latest(string $release, string $namespace, bool $metadata_only = false): ?array
    {
        $latest = null;

        foreach ($this->revisions($release, $namespace, $metadata_only) as $revision) {
            if (! $latest || $revision['version'] > $latest['version']) {
                $latest = $revision;
            }
        }

        return $latest;
    }

    /**
     * @return array<int, array{secret: string, version: int, status: string, payload: ?string}>
     */
    private function revisions(string $release, string $namespace, bool $metadata_only = false): array
    {
        $result = $this->client->list('v1', 'Secret', $namespace, "owner=helm,name={$release}", $metadata_only);

        if (! $result['success']) {
            return [];
        }

        $revisions = [];

        foreach ($result['data']['items'] ?? [] as $item) {
            $revisions[] = [
                'secret' => $item['metadata']['name'] ?? '',
                'version' => (int) ($item['metadata']['labels']['version'] ?? 0),
                'status' => $item['metadata']['labels']['status'] ?? '',
                'payload' => $item['data']['release'] ?? null,
            ];
        }

        return $revisions;
    }

    /**
     * Helm stores each release as base64(gzip(json)) inside a Secret, and the
     * Kubernetes API base64-encodes Secret data again — so two base64 layers
     * around the gzip stream.
     */
    private function decode(?string $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $bytes = base64_decode($payload, true);
        $inner = $bytes === false ? false : base64_decode($bytes, true);

        if ($inner !== false) {
            $bytes = $inner;
        }

        if ($bytes === false) {
            return null;
        }

        if (str_starts_with($bytes, "\x1f\x8b")) {
            $bytes = gzdecode($bytes);
        }

        $release = $bytes === false ? null : json_decode($bytes, true);

        return is_array($release) ? $release : null;
    }
}
