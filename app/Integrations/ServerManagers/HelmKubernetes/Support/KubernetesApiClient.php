<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\Support;

use App\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Minimal Kubernetes REST client — talks to the API server directly over
 * HTTPS, so neither `kubectl` nor `helm` has to be installed where this app
 * runs. Authenticates per-call from the Server's stored credentials (see
 * K8sCredentialContext).
 *
 * Every method returns ['success' => bool, 'status' => int, 'data' => ?array,
 * 'error' => string] rather than throwing on an API error, so callers can
 * map failures onto their own result shapes.
 *
 * apply() mirrors the permissions `kubectl apply` needed (get + create, or
 * get + patch for an object that already differs), rather than using
 * server-side apply, which would additionally require `patch` just to
 * create an object — see docs/k8s-rbac-sample.yaml.
 */
class KubernetesApiClient
{
    /**
     * Kinds this driver manages, so the common case needs no API discovery
     * round-trip: apiVersion => kind => [plural resource name, namespaced].
     * Anything else (e.g. a CRD) is looked up via API discovery.
     */
    private const RESOURCES = [
        'v1' => [
            'Namespace' => ['namespaces', false],
            'Secret' => ['secrets', true],
            'ConfigMap' => ['configmaps', true],
            'ServiceAccount' => ['serviceaccounts', true],
            'PersistentVolumeClaim' => ['persistentvolumeclaims', true],
            'Pod' => ['pods', true],
        ],
        'batch/v1' => [
            'Job' => ['jobs', true],
        ],
        'networking.k8s.io/v1' => [
            'Ingress' => ['ingresses', true],
        ],
        'rbac.authorization.k8s.io/v1' => [
            'RoleBinding' => ['rolebindings', true],
        ],
        'traefik.io/v1alpha1' => [
            'Middleware' => ['middlewares', true],
        ],
    ];

    private K8sCredentialContext $context;

    /** @var array<string, array<string, array{0: string, 1: bool}>> */
    private array $discovered = [];

    public function __construct(Server $server)
    {
        $this->context = new K8sCredentialContext($server);
    }

    /**
     * Create the manifest's object, or update it if it already exists.
     * Unchanged objects are left alone (no write issued), like
     * `kubectl apply`'s "unchanged".
     */
    public function apply(array $manifest, ?string $namespace = null): array
    {
        $api_version = (string) ($manifest['apiVersion'] ?? '');
        $kind = (string) ($manifest['kind'] ?? '');
        $name = (string) ($manifest['metadata']['name'] ?? '');
        $namespace = $manifest['metadata']['namespace'] ?? $namespace;

        if ($api_version === '' || $kind === '' || $name === '') {
            return $this->failure('Manifest requires apiVersion, kind and metadata.name');
        }

        return $this->request(function (PendingRequest $http) use ($manifest, $api_version, $kind, $name, $namespace) {
            $resource = $this->resolve($http, $api_version, $kind);

            if (! $resource) {
                return $this->failure("Unknown resource kind {$kind} ({$api_version})");
            }

            if ($resource[1] && ! $namespace) {
                return $this->failure("{$kind} {$name} requires a namespace");
            }

            $existing = $http->get($this->path($resource, $namespace, $name));

            if ($existing->status() === 404) {
                return $this->result($http->post($this->path($resource, $namespace), $manifest));
            }

            if (! $existing->successful()) {
                return $this->result($existing);
            }

            if ($this->contains($existing->json() ?? [], $manifest)) {
                return $this->result($existing);
            }

            return $this->result(
                $http->withBody(json_encode($manifest), 'application/merge-patch+json')
                    ->patch($this->path($resource, $namespace, $name))
            );
        });
    }

    public function get(string $api_version, string $kind, string $name, ?string $namespace = null): array
    {
        return $this->request(function (PendingRequest $http) use ($api_version, $kind, $name, $namespace) {
            $resource = $this->resolve($http, $api_version, $kind);

            if (! $resource) {
                return $this->failure("Unknown resource kind {$kind} ({$api_version})");
            }

            return $this->result($http->get($this->path($resource, $namespace, $name)));
        });
    }

    /**
     * Lists a kind's objects (the API's `items` are in ['data']['items']).
     * $metadata_only asks the API server to leave out each object's body —
     * e.g. the (potentially large) release payload of Helm's Secrets — when
     * only names/labels are needed.
     */
    public function list(string $api_version, string $kind, ?string $namespace = null, ?string $label_selector = null, bool $metadata_only = false): array
    {
        return $this->request(function (PendingRequest $http) use ($api_version, $kind, $namespace, $label_selector, $metadata_only) {
            $resource = $this->resolve($http, $api_version, $kind);

            if (! $resource) {
                return $this->failure("Unknown resource kind {$kind} ({$api_version})");
            }

            if ($metadata_only) {
                $http->accept('application/json;as=PartialObjectMetadataList;g=meta.k8s.io;v=v1, application/json');
            }

            return $this->result($http->get(
                $this->path($resource, $namespace),
                array_filter(['labelSelector' => $label_selector]),
            ));
        });
    }

    /**
     * Deletes an object. Already-gone is treated as success (like
     * `kubectl delete --ignore-not-found`).
     */
    public function delete(string $api_version, string $kind, string $name, ?string $namespace = null): array
    {
        return $this->request(function (PendingRequest $http) use ($api_version, $kind, $name, $namespace) {
            $resource = $this->resolve($http, $api_version, $kind);

            if (! $resource) {
                return $this->failure("Unknown resource kind {$kind} ({$api_version})");
            }

            // Background propagation is `kubectl delete`'s default; the API's
            // own default for some kinds (e.g. batch/v1 Job) orphans children.
            $response = $http->delete($this->path($resource, $namespace, $name), ['propagationPolicy' => 'Background']);

            if ($response->status() === 404) {
                return ['success' => true, 'status' => 404, 'data' => null, 'error' => ''];
            }

            return $this->result($response);
        });
    }

    /**
     * Logs of every pod matching the label selector (like `kubectl logs -l`),
     * concatenated. Each pod's default container is read: the one named by
     * its `kubectl.kubernetes.io/default-container` annotation, else the
     * first. On success the logs are in ['data'] as a string.
     */
    public function podLogs(string $label_selector, string $namespace): array
    {
        return $this->request(function (PendingRequest $http) use ($label_selector, $namespace) {
            $pods_path = $this->path($this->resolve($http, 'v1', 'Pod'), $namespace);
            $pods = $this->result($http->get($pods_path, ['labelSelector' => $label_selector]));

            if (! $pods['success']) {
                return $pods;
            }

            $logs = [];

            foreach ($pods['data']['items'] ?? [] as $pod) {
                $name = $pod['metadata']['name'] ?? null;

                if (! $name) {
                    continue;
                }

                $container = $pod['metadata']['annotations']['kubectl.kubernetes.io/default-container']
                    ?? $pod['spec']['containers'][0]['name']
                    ?? null;

                // log endpoint serves text/plain, not JSON
                $response = (clone $http)->accept('text/plain')->get(
                    "{$pods_path}/".rawurlencode($name).'/log',
                    array_filter(['container' => $container]),
                );

                if (! $response->successful()) {
                    return $this->result($response);
                }

                $logs[] = rtrim($response->body(), "\n");
            }

            return ['success' => true, 'status' => 200, 'data' => implode("\n", $logs), 'error' => ''];
        });
    }

    /**
     * Runs $callback(PendingRequest) once with credentials in place, turning
     * a connection-level failure into the same failure shape as an API error.
     */
    private function request(callable $callback): array
    {
        try {
            return $this->context->withRequest($callback);
        } catch (ConnectionException $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    /**
     * @return array{0: string, 1: string, 2: bool}|null [api path prefix, plural resource name, namespaced]
     */
    private function resolve(PendingRequest $http, string $api_version, string $kind): ?array
    {
        $prefix = str_contains($api_version, '/') ? "/apis/{$api_version}" : "/api/{$api_version}";

        $known = self::RESOURCES[$api_version][$kind] ?? $this->discover($http, $api_version, $prefix)[$kind] ?? null;

        return $known ? [$prefix, ...$known] : null;
    }

    /**
     * API discovery for a group/version: kind => [plural name, namespaced].
     * Only successful lookups are remembered, so a transient failure is
     * retried on the next call.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    private function discover(PendingRequest $http, string $api_version, string $prefix): array
    {
        if (isset($this->discovered[$api_version])) {
            return $this->discovered[$api_version];
        }

        $response = $http->get($prefix);

        if (! $response->successful()) {
            return [];
        }

        $kinds = [];

        foreach ($response->json('resources', []) as $resource) {
            // skip subresources like "pods/log"
            if (! str_contains($resource['name'], '/')) {
                $kinds[$resource['kind']] = [$resource['name'], (bool) $resource['namespaced']];
            }
        }

        return $this->discovered[$api_version] = $kinds;
    }

    private function path(array $resource, ?string $namespace, ?string $name = null): string
    {
        [$prefix, $plural, $namespaced] = $resource;

        $path = $prefix;

        if ($namespaced) {
            $path .= '/namespaces/'.rawurlencode((string) $namespace);
        }

        $path .= "/{$plural}";

        if ($name !== null) {
            $path .= '/'.rawurlencode($name);
        }

        return $path;
    }

    /**
     * Whether $current (what the API returned) already holds everything in
     * $desired. Extra fields on $current (defaulted/server-set) are fine.
     * Lists must match element for element, as a JSON merge patch replaces
     * them wholesale.
     */
    private function contains(mixed $current, mixed $desired): bool
    {
        if (! is_array($desired)) {
            return $current == $desired;
        }

        if (! is_array($current)) {
            return false;
        }

        if (array_is_list($desired)) {
            if (! array_is_list($current) || count($current) !== count($desired)) {
                return false;
            }

            foreach ($desired as $index => $value) {
                if (! $this->contains($current[$index], $value)) {
                    return false;
                }
            }

            return true;
        }

        foreach ($desired as $key => $value) {
            // null means "absent" in a merge patch
            if ($value === null) {
                if (($current[$key] ?? null) !== null) {
                    return false;
                }

                continue;
            }

            if (! array_key_exists($key, $current) || ! $this->contains($current[$key], $value)) {
                return false;
            }
        }

        return true;
    }

    private function result(Response $response): array
    {
        $data = $response->json();

        return [
            'success' => $response->successful(),
            'status' => $response->status(),
            'data' => is_array($data) ? $data : null,
            'error' => $response->successful() ? '' : $this->errorMessage($response),
        ];
    }

    // Kubernetes error responses are a Status object with a `message`.
    private function errorMessage(Response $response): string
    {
        $message = $response->json('message');

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return trim($response->body()) ?: 'HTTP '.$response->status();
    }

    private function failure(string $error): array
    {
        return ['success' => false, 'status' => 0, 'data' => null, 'error' => $error];
    }
}
