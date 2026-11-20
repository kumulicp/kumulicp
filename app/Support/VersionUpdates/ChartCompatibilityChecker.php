<?php

namespace App\Support\VersionUpdates;

use App\AppVersion;
use App\Server;
use Throwable;

/**
 * Decides whether a chart version can replace the one an app version uses,
 * for plain Kubernetes (Helm) and Rancher deployments, without the helm CLI:
 *
 *  - the package downloads and has a Helm 3 chart apiVersion (v1 or v2);
 *  - every value Kumulicp sets still exists in the chart's defaults;
 *  - Kumulicp's values pass the chart's values.schema.json;
 *  - each kubernetes cluster's Kubernetes version satisfies kubeVersion;
 *  - each Rancher server still lists the version once Rancher applies its
 *    own rancher-version / kube-version filtering.
 *
 * It also reports how the images the chart runs change, and warns about the
 * images the version sets itself that the chart no longer has a place for or
 * has moved past.
 */
class ChartCompatibilityChecker
{
    public const COMPATIBLE = 'compatible';

    public const INCOMPATIBLE = 'incompatible';

    public const UNKNOWN = 'unknown';

    private array $packages = [];

    public function __construct(
        private ValuesContract $contract = new ValuesContract,
        private ClusterVersion $cluster_version = new ClusterVersion,
        private ChartImageDiff $image_diff = new ChartImageDiff,
        private ChartImageScanner $image_scanner = new ChartImageScanner,
        private VersionComparator $comparator = new VersionComparator,
    ) {}

    /**
     * @param  array<int, Server>  $servers  servers running this app
     * @param  array<int, RancherChartRepository>  $rancher_repositories  one per Rancher server, for ClusterRepo charts
     */
    public function check(
        AppVersion $version,
        ChartRepository $repository,
        string $candidate,
        array $servers = [],
        array $rancher_repositories = [],
        ?array $rendered_values = null,
    ): array {
        $issues = [];
        $warnings = [];
        $image_changes = [];

        try {
            $package = $this->package($repository, $candidate);
        } catch (Throwable $exception) {
            return $this->result($candidate, self::UNKNOWN, [__('admin.version_updates.chart_download_failed', ['version' => $candidate, 'error' => $exception->getMessage()])]);
        }

        if (! in_array($package->apiVersion(), ['v1', 'v2'], true)) {
            $issues[] = __('admin.version_updates.unsupported_api_version', ['api_version' => (string) $package->apiVersion()]);
        }

        // Values contract against the chart version in use today
        $current = $version->setting('chart_version');
        try {
            $current_package = $current ? $this->package($repository, (string) $current) : null;
        } catch (Throwable) {
            $current_package = null;
        }

        if ($current_package) {
            $removed = $this->contract->removedKeys($rendered_values, $current_package->values, $package->values);

            foreach ($removed as $key) {
                if ($rendered_values !== null) {
                    $issues[] = __('admin.version_updates.value_removed', ['key' => $key]);
                } else {
                    $warnings[] = __('admin.version_updates.default_removed', ['key' => $key]);
                }
            }

            // The images the chart runs, and the ones this version sets itself
            $image_changes = $this->image_diff->lines($this->image_diff->compare($current_package, $package));
            array_push($warnings, ...$this->imageWarnings($version, $current_package, $package));
        } else {
            $warnings[] = __('admin.version_updates.current_chart_unavailable', ['version' => (string) $current]);
        }

        if ($rendered_values === null) {
            $warnings[] = __('admin.version_updates.no_instance_values');
        } elseif ($package->schema) {
            foreach ($this->contract->schemaErrors($rendered_values, $package->schema) as $error) {
                $issues[] = $error;
            }
        }

        // Per-server checks
        $targets = [];
        $rancher_by_server = [];
        foreach ($rancher_repositories as $rancher_repository) {
            $rancher_by_server[$rancher_repository->server()->id] = $rancher_repository;
        }

        foreach ($servers as $server) {
            $target = $this->checkServer($server, $package, $candidate, $rancher_by_server[$server->id] ?? null);
            $targets[] = $target;

            if ($target['status'] === self::INCOMPATIBLE) {
                $issues[] = $server->name.': '.$target['reason'];
            } elseif ($target['status'] === self::UNKNOWN && $target['reason']) {
                $warnings[] = $server->name.': '.$target['reason'];
            }
        }

        return $this->result($candidate, $issues ? self::INCOMPATIBLE : self::COMPATIBLE, $issues, $warnings, $targets, $image_changes);
    }

    /**
     * What is wrong with the images the version sets itself (see
     * AppVersion::extraImages()) against the chart it would move to: one the
     * chart no longer has a place for, and one older than the chart's own.
     * The first only counts for what the chart did have.
     *
     * @return array<int, string>
     */
    private function imageWarnings(AppVersion $version, ChartPackage $current, ChartPackage $candidate): array
    {
        $before = ChartImageScanner::byPath($this->image_scanner->scan($current));
        $after = ChartImageScanner::byPath($this->image_scanner->scan($candidate));
        $warnings = [];

        foreach ($version->extraImages() as $image) {
            $name = $image->label ?: $image->key;

            if (! isset($after[$image->values_path])) {
                if (isset($before[$image->values_path])) {
                    $warnings[] = __('admin.version_updates.image_path_missing', ['image' => $name, 'path' => $image->values_path]);
                }

                continue;
            }

            if ($this->comparator->isNewer($after[$image->values_path]['tag'], $image->effectiveTag())) {
                $warnings[] = __('admin.version_updates.image_older_than_chart', [
                    'image' => $name,
                    'tag' => (string) $image->effectiveTag(),
                    'chart_tag' => (string) $after[$image->values_path]['tag'],
                ]);
            }
        }

        return $warnings;
    }

    private function checkServer(Server $server, ChartPackage $package, string $candidate, ?RancherChartRepository $rancher_repository): array
    {
        $target = ['server' => $server->name, 'interface' => $server->interface, 'status' => self::COMPATIBLE, 'reason' => null];

        if ($server->interface === 'rancher') {
            if (! $rancher_repository) {
                // A URL chart repo can't be installed through a ClusterRepo
                return [...$target, 'status' => self::UNKNOWN, 'reason' => __('admin.version_updates.rancher_not_cluster_repo')];
            }

            try {
                $listed = array_key_exists($candidate, $rancher_repository->compatibleVersions());
            } catch (Throwable $exception) {
                return [...$target, 'status' => self::UNKNOWN, 'reason' => $exception->getMessage()];
            }

            return $listed ? $target : [...$target, 'status' => self::INCOMPATIBLE, 'reason' => __('admin.version_updates.rancher_filtered', [
                'rancher_version' => (string) $package->annotation('catalog.cattle.io/rancher-version'),
                'kube_version' => (string) ($package->annotation('catalog.cattle.io/kube-version') ?? $package->kubeVersion()),
            ])];
        }

        if ($server->interface === 'kubernetes') {
            $constraint = $package->kubeVersion();

            if (! $constraint) {
                return $target;
            }

            $cluster_version = $this->cluster_version->forServer($server);

            if (! $cluster_version) {
                return [...$target, 'status' => self::UNKNOWN, 'reason' => __('admin.version_updates.cluster_version_unknown')];
            }

            $satisfied = $this->cluster_version->satisfies($cluster_version, $constraint);

            if ($satisfied === false) {
                return [...$target, 'status' => self::INCOMPATIBLE, 'reason' => __('admin.version_updates.kube_version_mismatch', ['cluster' => $cluster_version, 'constraint' => $constraint])];
            }

            return $satisfied === null ? [...$target, 'status' => self::UNKNOWN, 'reason' => __('admin.version_updates.kube_version_unparsable', ['constraint' => $constraint])] : $target;
        }

        return [...$target, 'status' => self::UNKNOWN, 'reason' => null];
    }

    private function package(ChartRepository $repository, string $version): ChartPackage
    {
        $key = spl_object_id($repository).':'.$version;

        return $this->packages[$key] ??= ChartPackage::fromArchive($repository->package($version));
    }

    private function result(string $version, string $status, array $issues = [], array $warnings = [], array $targets = [], array $image_changes = []): array
    {
        return [
            'version' => $version,
            'status' => $status,
            'issues' => array_values($issues),
            'warnings' => array_values(array_unique($warnings)),
            'targets' => $targets,
            'image_changes' => array_values($image_changes),
        ];
    }
}
