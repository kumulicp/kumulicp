<?php

namespace App\Integrations\ServerManagers\Kubernetes\Charts;

use App\Support\Facades\Application;
use App\Support\Volumes\Volumes;
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
     * The configuration of a subchart, which the chart takes as it is, with the volumes of its workloads as
     * the volumes say: whether each is on, how big it is and its class (and its access mode, for one that takes
     * one). Only the volume that holds the files takes a claim, so one a configuration still has is cleared.
     *
     * @param  array<string, mixed>|null  $configuration
     * @param  array<string, array{0: string, 1: bool}>  $workloads  the workload's key in it => the volume's name, and whether it takes an access mode
     * @return array<string, mixed>|null
     */
    protected function withVolumes(?array $configuration, array $workloads, Volumes $volumes): ?array
    {
        if ($configuration === null) {
            return null;
        }

        foreach ($workloads as $key => [$name, $takes_access_mode]) {
            $volume = $volumes->get($name);
            $persistence = ['enabled' => $volume->enabled(), 'size' => $volume->size().'Gi', 'storageClass' => $volume->storageClass()];

            if ($takes_access_mode) {
                $persistence['accessMode'] = $volume->accessMode();
            }

            $kept = Arr::get($configuration, "{$key}.persistence", []);

            if (array_key_exists('existingClaim', $kept)) {
                $persistence['existingClaim'] = '';
            }

            $configuration[$key]['persistence'] = array_replace($kept, $persistence);
        }

        return $configuration;
    }

    public function appStorage()
    {
        // The size of the volume that holds the files, which follows the quota and only grows. A chart can be
        // given the storage of the release it belongs to; 0 is no storage worked out yet
        $volumes = Volumes::for($this->app_instance, $this->app_storage);

        if ($volumes->has('data')) {
            return $volumes->get('data')->size();
        }

        // The generic app has no volume of that name: its volumes are a list the plan defines, and the ones
        // with no size of their own are sized as the app was
        if (is_int($this->app_storage) && $this->app_storage > 0) {
            return $this->app_storage;
        }

        $storage = Application::instance($this->app_instance)->storage();
        $total_storage = (int) $storage->totalAppStorage();

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
