<?php

namespace App\Integrations\Applications\HelmForge;

use App\AppInstance;
use App\Enums\ApplicationSource;
use App\Integrations\ServerManagers\Kubernetes\Charts\HelmChart;
use App\Organization;
use App\Support\Facades\Application;
use App\Support\Volumes\VolumeSpec;
use Illuminate\Support\Arr;

/**
 * Builds the Helm values for any catalog app. Instead of a hand-written map
 * of Kumulicp settings to one chart's keys, it follows the `convention`
 * detected for the chart at sync time (ChartConventionDetector) and only
 * writes the keys that chart actually has.
 */
class HelmForgeChart extends HelmChart
{
    public function __construct(Organization $organization, AppInstance $app_instance)
    {
        parent::__construct($organization, $app_instance);

        $this->chart_name = $this->catalog()['chart_name'] ?? $app_instance->application->slug;
    }

    public function labelValues(): array
    {
        $values = [];

        foreach ($this->convention()['labels'] ?? [] as $path) {
            Arr::set($values, $path, $this->labels());
        }

        return $values;
    }

    public function values(): array
    {
        $convention = $this->convention();
        $app_instance = Application::instance($this->app_instance);
        $values = [];

        if ($path = $convention['replicas'] ?? null) {
            Arr::set($values, $path, $this->replicaCount());
        }

        if ($path = $convention['fullname'] ?? null) {
            Arr::set($values, $path, $app_instance->setOverrideIfEmpty('chart.values.fullNameOverride', $this->organization->slug.'-'.$this->chartName()));
        }

        if ($path = $convention['resources'] ?? null) {
            $this->setResources($values, $path);
        }

        if ($ingress = $convention['ingress'] ?? null) {
            $this->setIngress($values, $ingress);
        }

        if ($pull_secrets = $convention['pull_secrets'] ?? null) {
            $this->setPullSecrets($values, $pull_secrets);
        }

        if ($path = $convention['admin_password'] ?? null) {
            Arr::set($values, $path, $app_instance->api_password());
        }

        return array_replace_recursive($values, $this->volumeValues());
    }

    /**
     * The volume the chart was detected to have, at the keys it was detected to keep it. Only what
     * the volume has is written, so what the chart has no setting for, or the instance has no value
     * for, keeps the chart's own default.
     */
    public function volumes(): array
    {
        if (! $persistence = $this->convention()['persistence'] ?? null) {
            return [];
        }

        return [new VolumeSpec('app', 'data', primary: true, size_policy: 'quota', map: array_filter([
            'size' => $persistence['size'] ?? null,
            'storage_class' => $persistence['storage_class'] ?? null,
            'claim' => $persistence['existing_claim'] ?? null,
            'only_set' => true,
        ]))];
    }

    // Only the limits/requests the plan actually sets, so a blank option
    // keeps the chart's own default rather than clearing it.
    private function setResources(array &$values, string $path): void
    {
        $app_instance = Application::instance($this->app_instance);

        foreach (['requests', 'limits'] as $kind) {
            foreach (['cpu', 'memory'] as $resource) {
                $value = $app_instance->configuration("resources-$kind-$resource");

                if ($value !== null && $value !== '') {
                    Arr::set($values, "$path.$kind.$resource", $value);
                }
            }
        }
    }

    private function setIngress(array &$values, array $ingress): void
    {
        $app_instance = Application::instance($this->app_instance);
        $base = $ingress['path'];
        $domain = $app_instance->domain();
        $tls = (bool) $app_instance->configuration('ingress-tls');

        $annotations = array_filter([
            'cert-manager.io/cluster-issuer' => $tls ? $app_instance->configuration('ingress-annotation-cluster_issuer') : null,
            'traefik.ingress.kubernetes.io/router.middlewares' => $app_instance->configuration('ingress-annotation-traefik_middlewares'),
        ]);

        // If the app is disabled, also disable ingress so it isn't reachable from the internet without deleting the app and losing data
        Arr::set($values, "$base.enabled", $this->appEnabled() && ! $this->inMaintenance() && $app_instance->configuration('ingress-enabled'));
        Arr::set($values, "$base.annotations", $annotations);

        if ($ingress['style'] === 'hostname') {
            Arr::set($values, "$base.hostname", $domain);
            Arr::set($values, "$base.tls", $tls);

            return;
        }

        Arr::set($values, "$base.hosts", [[
            'host' => $domain,
            'paths' => [['path' => '/', 'pathType' => 'Prefix']],
        ]]);
        // Left out without TLS: Yaml::dump() renders an empty PHP array as
        // `{}`, which would override the chart's `[]` and fail its schema.
        if ($tls) {
            Arr::set($values, "$base.tls", [[
                'secretName' => $this->chartName().'-ingress-tls',
                'hosts' => [$domain],
            ]]);
        }
    }

    private function setPullSecrets(array &$values, array $pull_secrets): void
    {
        $names = $this->imagePullSecrets();

        if (! $names) {
            return;
        }

        Arr::set($values, $pull_secrets['path'], array_map(
            fn ($name) => $pull_secrets['format'] === 'name' ? $name : ['name' => $name],
            $names,
        ));
    }

    private function convention(): array
    {
        return $this->catalog()['convention'] ?? [];
    }

    // The data of the chart the instance is deployed from: its own when that is a
    // catalog chart added to an app that is deployed another way, else the app's
    private function catalog(): array
    {
        $chart = Application::chartFor($this->app_instance);

        if ($chart && ! $chart->is_default && $chart->source === ApplicationSource::HelmForge && $chart->catalog) {
            return $chart->catalog;
        }

        return $this->app_instance->application->catalog ?? [];
    }
}
