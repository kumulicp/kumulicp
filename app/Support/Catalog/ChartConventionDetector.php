<?php

namespace App\Support\Catalog;

use Illuminate\Support\Arr;

/**
 * Works out, from a chart's default values, which keys Kumulicp should write
 * its own settings (replicas, labels, ingress, storage, resources, ...) into.
 * Each hand-written chart class hardcodes this mapping; for catalog charts it
 * is detected once at sync time and stored on the application, so no PHP is
 * needed per app.
 *
 * Every entry is null when the chart doesn't expose that concern, and the
 * matching settings are then simply not sent.
 */
class ChartConventionDetector
{
    /**
     * @param  array  $overrides  per-chart replacements for detected entries (config/catalog.php)
     */
    public function detect(array $defaults, array $overrides = []): array
    {
        $convention = [
            'replicas' => Arr::has($defaults, 'replicaCount') ? 'replicaCount' : null,
            'fullname' => Arr::has($defaults, 'fullnameOverride') ? 'fullnameOverride' : null,
            'labels' => $this->labelPaths($defaults),
            'ingress' => $this->ingress($defaults),
            'persistence' => $this->persistence($defaults, $overrides['persistence_volume'] ?? null),
            'resources' => Arr::has($defaults, 'resources') ? 'resources' : null,
            'pull_secrets' => $this->pullSecrets($defaults),
            'admin_password' => $this->adminPassword($defaults),
        ];

        foreach ($overrides as $key => $value) {
            if (array_key_exists($key, $convention)) {
                $convention[$key] = $value;
            }
        }

        return $convention;
    }

    /**
     * Profile options (the plan-config form) for the concerns the chart
     * supports, using the same names the hand-written profiles use.
     */
    public function configurations(array $convention, array $defaults): array
    {
        $configs = [];

        if ($convention['replicas']) {
            $replicas = Arr::get($defaults, $convention['replicas']);

            $configs[] = $this->config('replicaCount', 'int', is_int($replicas) ? $replicas : 1, 'integer|required|min:0');
        }

        if ($convention['persistence']) {
            $configs[] = $this->config('persistence-storageClass', 'string', '', 'nullable|string', persistent: true);
        }

        if ($convention['resources']) {
            foreach (['requests', 'limits'] as $kind) {
                foreach (['cpu', 'memory'] as $resource) {
                    $value = Arr::get($defaults, "{$convention['resources']}.$kind.$resource");

                    $configs[] = $this->config("resources-$kind-$resource", 'string', is_scalar($value) ? (string) $value : '', 'nullable|string');
                }
            }
        }

        if ($convention['ingress']) {
            $configs[] = $this->config('ingress-enabled', 'bool', true, 'nullable|boolean');
            $configs[] = $this->config('ingress-tls', 'bool', false, 'nullable|boolean');
            $configs[] = $this->config('ingress-annotation-cluster_issuer', 'string', 'letsencrypt-production', 'nullable|string');
            $configs[] = $this->config('ingress-annotation-traefik_middlewares', 'string', 'https-redirect@kubernetescrd', 'nullable|string');
        }

        // Same keyed-by-name shape as AppProfile::$configurations
        return collect($configs)->keyBy('name')->all();
    }

    private function config(string $name, string $type, mixed $default, string $validations, bool $persistent = false): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'persistent' => $persistent,
            'default' => $default,
            'validations' => $validations,
        ];
    }

    // Prefer commonLabels (labels every resource); otherwise pod labels, so
    // NetworkPolicies can still select the workload by its kumulicp.io labels.
    private function labelPaths(array $defaults): array
    {
        if (Arr::has($defaults, 'commonLabels')) {
            return ['commonLabels'];
        }

        return array_values(array_filter(
            ['podLabels', 'labels'],
            fn ($path) => Arr::has($defaults, $path),
        ));
    }

    private function ingress(array $defaults): ?array
    {
        $ingress = $defaults['ingress'] ?? null;

        if (! is_array($ingress) || ! array_key_exists('enabled', $ingress)) {
            return null;
        }

        if (array_key_exists('hosts', $ingress)) {
            return ['path' => 'ingress', 'style' => 'hosts'];
        }

        if (array_key_exists('hostname', $ingress)) {
            return ['path' => 'ingress', 'style' => 'hostname'];
        }

        return null;
    }

    /**
     * Flat charts keep `persistence.size`; charts with several volumes keep
     * `persistence.<volume>.size`, in which case one volume is picked: the
     * override, else the first enabled one.
     */
    private function persistence(array $defaults, ?string $volume): ?array
    {
        $persistence = $defaults['persistence'] ?? null;

        if (! is_array($persistence)) {
            return null;
        }

        if (array_key_exists('size', $persistence)) {
            $base = 'persistence';
        } else {
            $volumes = array_filter($persistence, fn ($value) => is_array($value) && array_key_exists('size', $value));

            if (! $volumes) {
                return null;
            }

            $volume = $volume && isset($volumes[$volume])
                ? $volume
                : (array_key_first(array_filter($volumes, fn ($value) => ($value['enabled'] ?? false) === true)) ?? array_key_first($volumes));

            $base = "persistence.$volume";
        }

        return [
            'size' => "$base.size",
            'storage_class' => Arr::has($defaults, "$base.storageClass") ? "$base.storageClass" : null,
            'existing_claim' => Arr::has($defaults, "$base.existingClaim") ? "$base.existingClaim" : null,
        ];
    }

    // Image pull secrets are a list in every chart; remember whether items are
    // plain names (Bitnami style) or {name: ...} objects (Kubernetes style).
    private function pullSecrets(array $defaults): ?array
    {
        if (! Arr::has($defaults, 'imagePullSecrets')) {
            return null;
        }

        $first = Arr::get($defaults, 'imagePullSecrets')[0] ?? null;

        return ['path' => 'imagePullSecrets', 'format' => is_string($first) ? 'name' : 'object'];
    }

    // The first `<something>.admin.password` / `auth.adminPassword`-style key,
    // searched a few levels deep so `paperless.admin.password` is found too.
    private function adminPassword(array $defaults): ?string
    {
        return $this->findAdminPassword($defaults, [], 3);
    }

    private function findAdminPassword(array $values, array $prefix, int $depth): ?string
    {
        foreach ($values as $key => $value) {
            $path = [...$prefix, (string) $key];

            if (is_array($value) && ! array_is_list($value) && $depth > 0) {
                if ($found = $this->findAdminPassword($value, $path, $depth - 1)) {
                    return $found;
                }

                continue;
            }

            if (! is_array($value) && $this->isAdminPasswordPath($path)) {
                return implode('.', $path);
            }
        }

        return null;
    }

    private function isAdminPasswordPath(array $path): bool
    {
        $leaf = end($path);
        $parent = $path[count($path) - 2] ?? null;

        return ($parent === 'admin' && $leaf === 'password')
            || $leaf === 'adminPassword';
    }
}
