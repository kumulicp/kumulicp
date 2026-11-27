<?php

namespace App\Integrations\ServerManagers\Kubernetes\Charts;

use App\Support\Facades\Application;
use App\Support\Volumes\LegacyVolumeKeys;
use App\Support\Volumes\Volume;
use App\Support\Volumes\Volumes;
use App\Support\Volumes\VolumeSpec;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

abstract class HelmChart extends Chart
{
    public $app_storage;

    private ?bool $awaitingActivation = null;

    public $delete_method = 'remove';

    public $chart_name = '';

    // Array configuration prefixes (`<prefix>-<index>-<field>`) that values()
    // consumes itself. valuesWithAdditionalConfigs() skips them instead of also
    // setting them as raw Helm value paths.
    public $handled_array_prefixes = [];

    abstract public function values(): array;

    public function chartName()
    {
        if ($this->name === $this->app_instance->application->name) {
            return $this->format($this->app_instance->setOverrideIfEmpty('chart.name', $this->name));
        } else {
            return $this->format($this->app_instance->setOverrideIfEmpty("chart.{$this->chart_name}.name", $this->name));
        }
    }

    protected function secretsName(): string
    {
        return Str::slug($this->chartName().'-secrets');
    }

    protected function secretsRelease(): ?string
    {
        return Str::slug($this->chartName());
    }

    // The Helm values that apply the kumulicp labels. Charts differ in which
    // keys they accept, so override this for charts without `commonLabels`
    // (which labels every resource the chart deploys).
    public function labelValues(): array
    {
        return ['commonLabels' => $this->labels()];
    }

    // The Helm values array for this chart, merged with the kumulicp labels and
    // the plan's additional configs. Shared by any server-manager driver — not
    // Rancher-specific.
    public function valuesWithAdditionalConfigs(): array
    {
        $app_instance = Application::instance($this->app_instance);
        $values = array_replace_recursive($this->modifyValues($this->values()), $this->labelValues());
        $additionalConfigs = $this->app_instance->plan->additionalConfigs();

        foreach ($additionalConfigs as $config) {
            if ($this->handlesArrayConfig($config['name'])) {
                continue;
            }

            $key = str_replace('-', '.', $config['name']);
            Arr::set($values, $key, $app_instance->configuration($config['name']));
        }

        // Last, so a configuration that happens to name the same Helm path (a plan
        // seeds every default) can't put back an image other than the one that was tested
        return $this->applyImageOverrides($values);
    }

    // Writes the version's images besides the primary one (which each chart sets
    // itself) at the values path they were given: `<path>.registry`, `.repository`,
    // `.tag` and, when there is one, `.digest`. Only what the image has is written,
    // as a null would make Helm drop the chart's own default, and what else the
    // chart keeps under that path (pullPolicy, ...) is left as it is.
    public function applyImageOverrides(array $values): array
    {
        $version = $this->app_instance->version;

        if (! $version) {
            return $values;
        }

        foreach ($version->extraImages() as $image) {
            $path = trim($image->values_path, '. ');

            if ($path === '') {
                continue;
            }

            Arr::set($values, $path.'.repository', $image->repository);

            foreach (['registry' => $image->effectiveRegistry(), 'tag' => $image->effectiveTag(), 'digest' => $image->digest] as $key => $value) {
                if ($value) {
                    Arr::set($values, $path.'.'.$key, $value);
                }
            }
        }

        return $values;
    }

    private function handlesArrayConfig(string $name): bool
    {
        foreach ($this->handled_array_prefixes as $prefix) {
            if (preg_match('/^'.preg_quote($prefix, '/').'-\d+-/', $name)) {
                return true;
            }
        }

        return false;
    }

    public function buildChart()
    {
        $app_instance = Application::instance($this->app_instance);
        $values = $this->valuesWithAdditionalConfigs();

        $chart = [
            'charts' => [
                [
                    'chartName' => $this->helmChartName(), // The generic chart name in the repo
                    'version' => $this->helmChartVersion(),
                    'releaseName' => $this->chartName(), // The chart name of this specific instance
                    'values' => $values,
                ],
            ],
            'noHooks' => false,
            'timeout' => '720s',
            'wait' => true,
            'namespace' => $this->organization->slug,
            'projectId' => null,
            'disableOpenAPIValidation' => false,
            'skipCRDs' => false,
        ];

        return $chart;
    }

    // Where this release's chart comes from: the app's version says, unless the chart
    // is not the app's own (see MaintenanceChart).
    public function helmChartName(): ?string
    {
        return $this->app_instance->version->chartName();
    }

    public function helmChartVersion(): ?string
    {
        $version = $this->app_instance->version->setting('chart_version');

        return $version === null ? null : (string) $version;
    }

    // The chart repository: a plain https URL, an oci:// reference, or for Rancher the name of a ClusterRepo
    public function helmRepo(): ?string
    {
        return $this->app_instance->version->helmRepo();
    }

    // The credentials of a private chart repository
    public function helmRepoSecret()
    {
        $version = $this->app_instance->version;

        return $version->requiresHelmRepoAuth() ? $version->chartRepoSecret() : null;
    }

    /**
     * The volumes this chart deploys, which it says where in its values it keeps (VolumeSpec::$map).
     * A chart that declares none writes its own persistence values, with appStorage() for the size.
     *
     * @return array<int, VolumeSpec>
     */
    public function volumes(): array
    {
        return [];
    }

    /**
     * The volume of a chart that keeps it where most do: `persistence.{enabled,size,storageClass,accessModes,existingClaim}`,
     * its access modes a list, and sized by the storage quota. A chart that differs in a field says so.
     *
     * @param  array<string, mixed>  $map  what differs in where the chart keeps it
     */
    protected function persistenceVolume(array $map = []): VolumeSpec
    {
        return new VolumeSpec('app', 'data', primary: true, size_policy: 'quota', map: $map + [
            'path' => 'persistence',
            'enabled' => 'enabled',
            'size' => 'size',
            'storage_class' => 'storageClass',
            'access_mode' => 'accessModes',
            'access_mode_as' => 'list',
            'claim' => 'existingClaim',
        ]);
    }

    protected function volumeStore(): Volumes
    {
        // Where this chart's instances kept their volume before, which is the chart's to say
        return Volumes::for($this->app_instance, $this->volumes(), LegacyVolumeKeys::layoutOf(static::class));
    }

    /**
     * The values of every volume the chart declares, at the keys it says it keeps them in. The chart
     * puts these over its own (array_replace_recursive), and writes none of them itself.
     *
     * @return array<string, mixed>
     */
    public function volumeValues(): array
    {
        $store = $this->volumeStore();
        $values = [];

        foreach ($store->specs() as $spec) {
            if ($spec->map !== []) {
                $this->writeVolume($values, $spec, $store->get($spec->workload, $spec->name), $store);
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function writeVolume(array &$values, VolumeSpec $spec, Volume $volume, Volumes $store): void
    {
        $map = $spec->map;
        $only_set = $map['only_set'] ?? false;

        $fields = [
            'enabled' => $volume->enabled,
            'size' => $this->volumeSize($spec, $store).'Gi',
            'storage_class' => $volume->storage_class,
            'access_mode' => ($map['access_mode_as'] ?? null) === 'list' ? ($volume->access_modes ?: null) : $volume->access_mode,
            // Said as blank, in the way the chart takes it, unless the chart is only given what is set
            'claim' => $only_set ? $volume->claim : ($volume->claim ?? (array_key_exists('blank_claim', $map) ? $map['blank_claim'] : '')),
        ];

        foreach ($fields as $field => $value) {
            if (! isset($map[$field]) || ($only_set && ($value === null || $value === ''))) {
                continue;
            }

            Arr::set($values, isset($map['path']) ? "{$map['path']}.{$map[$field]}" : $map[$field], $value);
        }
    }

    /**
     * The size, in Gi, of a volume the chart declares.
     */
    protected function volumeGibibytes(string $workload, string $name): int
    {
        $store = $this->volumeStore();

        return $this->volumeSize($store->specs()["{$workload}/{$name}"], $store);
    }

    private function volumeSize(VolumeSpec $spec, Volumes $store): int
    {
        // A chart can be given the storage of the release it belongs to
        if ($spec->size_policy === 'quota' && is_int($this->app_storage) && $this->app_storage > 0) {
            return $this->app_storage;
        }

        return $store->size($spec->workload, $spec->name, $spec->size_policy === 'quota' ? $this->storageQuota() : 0);
    }

    /**
     * What the subscription gives the app to store, in Gi: 0 when it is not worked out yet.
     */
    protected function storageQuota(): int
    {
        return (int) Application::instance($this->app_instance)->storage()->totalAppStorage();
    }

    public function appStorage()
    {
        // A chart can be given the storage of the release it belongs to; 0 is no storage worked out yet
        if (is_int($this->app_storage) && $this->app_storage > 0) {
            return $this->app_storage;
        }

        $total_storage = $this->storageQuota();

        $chart_storage = (int) $this->app_instance->setting('chart_storage');
        // Not a 0: the next chart built for this instance would take it for the size
        if ($total_storage > 0 && (! $chart_storage || $chart_storage < $total_storage)) {
            $this->app_instance->updateSetting('chart_storage', $total_storage);
            $chart_storage = $total_storage;
        }

        return $chart_storage > 0 ? $chart_storage : 1;
    }

    public function clusterIssuer()
    {
        return app()->environment('production') ? 'letsencrypt-production' : 'letsencrypt-staging';
    }

    public function appEnabled()
    {
        // An app that is yet to be activated is built as the enabled app it will become,
        // so its chart (e.g. in the activation review) isn't scaled to 0 with ingress off
        if ($this->organization->status == 'deactivated') {
            return false;
        }

        if ($this->app_instance->status != 'deactivated') {
            return true;
        }

        return $this->awaitingActivation ??= $this->app_instance->isAwaitingActivation();
    }

    // Whether the app is in maintenance mode: its ingress is turned off, and the
    // maintenance release serves the page at its domain. An app that is disabled
    // has no ingress at all, so there is no page to serve either.
    public function inMaintenance(): bool
    {
        return $this->app_instance->inMaintenance() && $this->appEnabled();
    }

    public function replicaCount()
    {
        $expand_storage = $this->app_instance->setting('expand_storage');
        // Stopped by a move to another server, so that its volume can be copied
        $moving = $this->app_instance->setting('quiesced_for_move') === true;

        if ($expand_storage === true || $moving || ! $this->appEnabled()) {
            return 0;
        }

        return Application::instance($this->app_instance)->configuration('replicaCount') ?? 1;
    }

    public function format(string $string)
    {
        return Str::replace('_', '-', $string);
    }
}
