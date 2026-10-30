<?php

namespace App\Integrations\ServerManagers\Rancher\Support;

use App\Exceptions\ConnectionFailedException;
use App\Integrations\ServerManagers\Kubernetes\Support\KubernetesApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * KubernetesApiClient for a cluster managed through Rancher. The public
 * methods and result shape are unchanged; what differs is how Rancher wants
 * to be talked to:
 *
 *   - Rancher's own API (Steve) serves every kind at /v1/{group.}{plural},
 *     with the namespace after the collection rather than before it, and
 *     creates by POSTing to the collection without a namespace.
 *   - Objects are replaced with a PUT of the whole object, not merge-patched.
 *   - Pods (listing and logs) are read through the standard Kubernetes API
 *     Rancher serves at its root, because Steve's list shape and lack of a
 *     log endpoint don't fit.
 *   - Server errors and unreachable servers throw, as this driver always
 *     has, rather than returning a failed result for the caller to check.
 *
 * Rancher-only operations (catalog installs) are exposed through action().
 */
class RancherApiClient extends KubernetesApiClient
{
    /** HTTP statuses that mean the request itself went wrong, not that something is absent. */
    private const FATAL_STATUSES = [400, 401, 405, 422, 500, 503, 504];

    // Rancher's own catalog kinds, on top of what the Kubernetes client already knows.
    protected const RESOURCES = parent::RESOURCES + [
        'catalog.cattle.io/v1' => [
            'App' => ['apps', true],
            'ClusterRepo' => ['clusterrepos', false],
        ],
    ];

    /** Kinds read through the standard Kubernetes API instead of Steve. */
    private const STANDARD_API_PLURALS = ['pods'];

    /**
     * POSTs to a Rancher action URL (e.g. a catalog repo's `?action=install`).
     */
    public function action(string $path, array $body = []): array
    {
        return $this->request(fn (PendingRequest $http) => $this->result($http->post($path, $body)));
    }

    protected function path(array $resource, ?string $namespace, ?string $name = null): string
    {
        if ($this->usesStandardApi($resource)) {
            return parent::path($resource, $namespace, $name);
        }

        $path = $this->createPath($resource, $namespace);

        if ($resource[2] && $namespace) {
            $path .= '/'.rawurlencode($namespace);
        }

        if ($name !== null) {
            $path .= '/'.rawurlencode($name);
        }

        return $path;
    }

    protected function createPath(array $resource, ?string $namespace): string
    {
        if ($this->usesStandardApi($resource)) {
            return parent::createPath($resource, $namespace);
        }

        return '/v1/'.$this->steveType($resource);
    }

    // Rancher takes the whole object back, so start from what it returned and
    // lay the manifest over it: metadata is merged (to keep resourceVersion
    // and anything Rancher added), everything else is replaced.
    protected function updateObject(PendingRequest $http, array $resource, ?string $namespace, string $name, array $manifest, array $current): Response
    {
        $object = $current;

        foreach ($manifest as $key => $value) {
            $object[$key] = $key === 'metadata' ? array_replace_recursive($current['metadata'] ?? [], $value) : $value;
        }

        return $http->put($this->path($resource, $namespace, $name), $object);
    }

    // Rancher deletes without options.
    protected function deleteOptions(): array
    {
        return [];
    }

    protected function result(Response $response): array
    {
        if (in_array($response->status(), self::FATAL_STATUSES, true)) {
            throw new ConnectionFailedException("Rancher request failed [{$response->status()}]: ".$this->errorMessage($response));
        }

        return parent::result($response);
    }

    protected function connectionFailure(ConnectionException $exception): array
    {
        throw new ConnectionFailedException('Rancher is unreachable: '.$exception->getMessage(), 0, $exception);
    }

    private function usesStandardApi(array $resource): bool
    {
        return in_array($resource[1], self::STANDARD_API_PLURALS, true);
    }

    // `networking.k8s.io.ingresses`, or just `secrets` for the core group.
    private function steveType(array $resource): string
    {
        [$prefix, $plural] = $resource;

        // $prefix is /api/v1 (core) or /apis/{group}/{version}
        $group = str_starts_with($prefix, '/apis/') ? explode('/', $prefix)[2] : '';

        return $group === '' ? $plural : "{$group}.{$plural}";
    }
}
