<?php

namespace App\Support\Security\Concerns;

use App\Support\Security\NamespaceSecurityPolicy;
use App\Support\Security\NamespaceSecurityReconciler;
use Illuminate\Support\Facades\Log;

/**
 * Shared "bring this namespace's Pod Security labels in line with its plan's
 * tier" flow for the KubernetesNamespace classes of both server drivers.
 * Each driver supplies only how to read and write the namespace metadata.
 *
 * Expects the using class to expose $organization and $org_server.
 */
trait ReconcilesNamespaceSecurity
{
    /**
     * @return array{labels: array<string, string>, annotations: array<string, string>}|array{error: string}
     */
    abstract protected function currentNamespaceMetadata(): array;

    /**
     * @param  array{labels: array<string, string|null>, annotations: array<string, string|null>}  $changes  merge-patch values; null removes the key
     * @return array{success: bool, error: string}
     */
    abstract protected function applyNamespaceMetadata(array $changes): array;

    /**
     * What this namespace should look like, or null when KumuliCP doesn't
     * manage its security labels (the default).
     *
     * @return array{tier: string, labels: array<string, string>}|null
     */
    public function desiredSecurity(): ?array
    {
        return NamespaceSecurityPolicy::desired($this->organization, $this->org_server->server);
    }

    /**
     * `action` is one of unmanaged, unchanged, would_update, updated or error.
     *
     * @return array{status: string, action: string, changes: array, response?: string}
     */
    public function reconcileSecurity(bool $apply = true): array
    {
        $empty = ['labels' => [], 'annotations' => []];
        $desired = $this->desiredSecurity();

        // The default: no server calls at all
        if ($desired === null) {
            return ['status' => 'success', 'action' => 'unmanaged', 'changes' => $empty];
        }

        $current = $this->currentNamespaceMetadata();

        if (isset($current['error'])) {
            return ['status' => 'failed', 'action' => 'error', 'changes' => $empty, 'response' => $current['error']];
        }

        $changes = NamespaceSecurityReconciler::changes($desired, $current['labels'], $current['annotations']);

        if (NamespaceSecurityReconciler::isEmpty($changes)) {
            return ['status' => 'success', 'action' => 'unchanged', 'changes' => $empty];
        }

        if (! $apply) {
            return ['status' => 'success', 'action' => 'would_update', 'changes' => $changes];
        }

        $result = $this->applyNamespaceMetadata($changes);

        if (! $result['success']) {
            Log::warning("Couldn't update security labels on namespace {$this->organization->slug}: {$result['error']}", ['organization_id' => $this->organization->id]);

            return ['status' => 'failed', 'action' => 'error', 'changes' => $changes, 'response' => $result['error']];
        }

        return ['status' => 'success', 'action' => 'updated', 'changes' => $changes];
    }
}
