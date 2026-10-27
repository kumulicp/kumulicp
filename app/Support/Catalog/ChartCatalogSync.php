<?php

namespace App\Support\Catalog;

use App\Application;
use App\AppVersion;
use App\ChartSource;
use App\Support\Facades\Application as ApplicationFacade;
use App\Support\VersionUpdates\ChartPackage;
use App\Support\VersionUpdates\HttpChartRepository;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports every chart of a chart source as a (disabled) application with its
 * newest versions, plus the convention data CatalogProfile/CatalogChart use
 * to deploy it. Safe to re-run: existing apps and versions keep any admin
 * edits, and a chart is only downloaded again when it has a new version.
 */
class ChartCatalogSync
{
    // The concerns whose absence makes a chart "limited" in the admin list
    private const CORE_CONCERNS = ['ingress', 'persistence', 'replicas', 'resources'];

    public function __construct(private ChartConventionDetector $detector) {}

    /**
     * @return array{created: array<int, string>, updated: array<int, string>, skipped: array<string, string>, failed: array<string, string>, limited: array<string, array<int, string>>, error: string|null}
     */
    public function sync(ChartSource $source): array
    {
        $report = ['created' => [], 'updated' => [], 'skipped' => [], 'failed' => [], 'limited' => [], 'error' => null];
        $secret = $source->helmRepoSecret?->requiresAuth() ? $source->helmRepoSecret : null;

        try {
            $index = (new HttpChartRepository($source->url, '', $secret))->index();
        } catch (Throwable $exception) {
            $report['error'] = $exception->getMessage();
            $source->update(['last_sync_error' => $report['error']]);

            return $report;
        }

        foreach ($index as $chart_name => $entries) {
            if ($reason = $this->skipReason($source, $chart_name, $entries)) {
                $report['skipped'][$chart_name] = $reason;

                continue;
            }

            try {
                $repository = (new HttpChartRepository($source->url, $chart_name, $secret))->withEntries($entries);
                $result = $this->syncChart($source, $chart_name, $entries, $repository);

                $report[$result['created'] ? 'created' : 'updated'][] = $chart_name;

                if ($result['missing']) {
                    $report['limited'][$chart_name] = $result['missing'];
                }
            } catch (Throwable $exception) {
                $report['failed'][$chart_name] = $exception->getMessage();
            }
        }

        $source->update(['last_synced_at' => now(), 'last_sync_error' => null]);

        return $report;
    }

    private function skipReason(ChartSource $source, string $chart_name, array $entries): ?string
    {
        if (in_array($chart_name, config('catalog.exclude', []), true)) {
            return 'excluded';
        }

        if (! $entries || ($entries[0]['type'] ?? 'application') === 'library') {
            return 'not an application chart';
        }

        // A hand-written profile, or an app an admin created themselves, wins
        if (ApplicationFacade::isRegistered($chart_name)) {
            return 'shadowed by a built-in profile';
        }

        $existing = Application::where('slug', $chart_name)->first();

        if ($existing && $existing->chart_source_id !== $source->id) {
            return 'an app with this slug already exists';
        }

        return null;
    }

    /**
     * @return array{created: bool, missing: array<int, string>}
     */
    private function syncChart(ChartSource $source, string $chart_name, array $entries, HttpChartRepository $repository): array
    {
        $latest = $entries[0];
        $latest_version = (string) $latest['version'];

        $application = Application::where('slug', $chart_name)->first() ?? new Application;
        $application->slug = $chart_name;
        $created = ! $application->exists;
        $catalog = $application->catalog ?? [];

        // Detection needs the chart's values, so only download on a new version
        if (($catalog['chart_version'] ?? null) !== $latest_version) {
            $package = ChartPackage::fromArchive($repository->package($latest_version));
            $catalog = $this->catalog($chart_name, $latest, $package);
        }

        if ($created) {
            $application->name = Str::headline($chart_name);
            $application->short_description = '';
            $application->description = (string) ($latest['description'] ?? '');
            $application->enabled = false;
        }

        $application->chart_source_id = $source->id;
        $application->catalog = $catalog;
        $application->save();

        foreach (array_slice($entries, 0, (int) config('catalog.versions_per_chart', 3)) as $entry) {
            $this->syncVersion($source, $application, $entry);
        }

        return ['created' => $created, 'missing' => $this->missingConcerns($catalog['convention'])];
    }

    private function catalog(string $chart_name, array $entry, ChartPackage $package): array
    {
        $convention = $this->detector->detect($package->values, config("catalog.overrides.$chart_name", []));

        return [
            'chart_name' => $chart_name,
            'chart_version' => (string) $entry['version'],
            'app_version' => isset($entry['appVersion']) ? (string) $entry['appVersion'] : null,
            'icon' => $entry['icon'] ?? null,
            'home' => $entry['home'] ?? null,
            'keywords' => $entry['keywords'] ?? [],
            'convention' => $convention,
            'configurations' => $this->detector->configurations($convention, $package->values),
        ];
    }

    // Named by chart version: several chart versions can ship the same
    // appVersion, and the name has to be unique per application.
    private function syncVersion(ChartSource $source, Application $application, array $entry): void
    {
        $name = (string) $entry['version'];

        if (AppVersion::where('application_id', $application->id)->where('name', $name)->exists()) {
            return;
        }

        $version = new AppVersion;
        $version->application_id = $application->id;
        $version->name = $name;
        $version->settings = [
            'chart_name' => $application->catalog['chart_name'],
            'chart_version' => (string) $entry['version'],
            'helm_repo_name' => $source->url,
        ];
        $version->helm_repo_secret_id = $source->helm_repo_secret_id;
        $version->status = 'deactivated';
        $version->save();
    }

    private function missingConcerns(array $convention): array
    {
        return array_values(array_filter(
            self::CORE_CONCERNS,
            fn ($concern) => empty($convention[$concern]),
        ));
    }
}
