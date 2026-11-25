<?php

namespace App\Support\Catalog;

use App\AppChart;
use App\Application;
use App\AppVersion;
use App\AppVersionGroup;
use App\ChartSource;
use App\Enums\ApplicationSource;
use App\Support\Facades\Application as ApplicationFacade;
use App\Support\VersionUpdates\ChartPackage;
use App\Support\VersionUpdates\HttpChartRepository;
use App\Tag;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Imports every chart of the HelmForge chart source as a (disabled) application
 * with its newest versions, plus the convention data HelmForgeProfile/HelmForgeChart
 * use to deploy it. Other chart repositories have their own values schema, so
 * they need their own profile and chart class before they can be synced. Safe to re-run: existing apps and versions keep any admin
 * edits, and a chart is only downloaded again when it has a new version.
 */
class ChartCatalogSync
{
    // The concerns whose absence makes a chart "limited" in the admin list
    private const CORE_CONCERNS = ['ingress', 'persistence', 'replicas', 'resources'];

    // Why a chart is not imported as an app of its own: a hand-written profile has the name
    private const SHADOWED = 'shadowed by a built-in profile';

    public function __construct(private ChartConventionDetector $detector) {}

    /**
     * @return array{created: array<int, string>, updated: array<int, string>, skipped: array<string, string>, failed: array<string, string>, limited: array<string, array<int, string>>, error: string|null}
     */
    public function sync(ChartSource $source): array
    {
        $report = ['created' => [], 'updated' => [], 'skipped' => [], 'failed' => [], 'limited' => [], 'error' => null];
        $secret = $source->helmRepoSecret?->requiresAuth() ? $source->helmRepoSecret : null;

        if (ApplicationSource::forChartSource($source) !== ApplicationSource::HelmForge) {
            $report['error'] = "Chart source {$source->slug} has no supported chart schema, only HelmForge charts can be imported";
            $source->update(['last_sync_error' => $report['error']]);

            return $report;
        }

        try {
            $index = (new HttpChartRepository($source->url, '', $secret))->index();
        } catch (Throwable $exception) {
            $report['error'] = $exception->getMessage();
            $source->update(['last_sync_error' => $report['error']]);

            return $report;
        }

        foreach ($index as $chart_name => $entries) {
            if ($reason = $this->skipReason($source, $chart_name, $entries)) {
                if ($reason === self::SHADOWED && $application = $this->shadowedApplication($chart_name)) {
                    $this->attachToShadowed($source, $application, $report);
                } else {
                    $report['skipped'][$chart_name] = $reason;
                }

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

        $this->syncAttachedCharts($source, $report);

        $source->update(['last_synced_at' => now(), 'last_sync_error' => null]);

        return $report;
    }

    /**
     * The app a chart of the same name is added to, when the name is that of a
     * registered profile and the app exists: the chart becomes another one of it.
     */
    private function shadowedApplication(string $chart_name): ?Application
    {
        return Application::where('slug', $chart_name)->first();
    }

    /**
     * Adds the chart to the app the first time it is seen (off, see attach()). One
     * already added is brought up to date by syncAttachedCharts().
     *
     * @param  array<string, mixed>  $report
     */
    private function attachToShadowed(ChartSource $source, Application $application, array &$report): void
    {
        $exists = $application->charts()->where('chart_source_id', $source->id)->where('is_default', false)->exists();

        if ($exists) {
            return;
        }

        $label = "{$application->slug} ({$source->slug})";

        try {
            $this->attach($source, $application);
            $report['created'][] = $label;
        } catch (Throwable $exception) {
            $report['failed'][$label] = $exception->getMessage();
        }
    }

    /**
     * Keeps the charts this source was added to other apps as up to date too.
     *
     * @param  array<string, mixed>  $report
     */
    private function syncAttachedCharts(ChartSource $source, array &$report): void
    {
        $charts = AppChart::where('chart_source_id', $source->id)
            ->where('is_default', false)
            ->where('source', ApplicationSource::HelmForge->value)
            ->with('application')
            ->get();

        foreach ($charts as $chart) {
            $label = "{$chart->application->slug} ({$source->slug})";

            try {
                $this->attach($source, $chart->application, $chart->chart_name);
                $report['updated'][] = $label;
            } catch (Throwable $exception) {
                $report['failed'][$label] = $exception->getMessage();
            }
        }
    }

    /**
     * Adds a chart of the source to an app that is deployed another way (the
     * registered Nextcloud chart gets HelmForge's as a second one), so plans
     * can choose between them. The new chart is off until an admin enables it,
     * and has the newest versions of the chart in a group of its own. Safe to
     * repeat: it brings an added chart up to date.
     *
     * @return array{chart: AppChart, created: bool, skipped: array<string, string>}
     *
     * @throws RuntimeException when the source or its chart can't be used
     */
    public function attach(ChartSource $source, Application $application, ?string $chart_name = null): array
    {
        if (ApplicationSource::forChartSource($source) !== ApplicationSource::HelmForge) {
            throw new RuntimeException("Chart source {$source->slug} has no supported chart schema, only HelmForge charts can be added");
        }

        // An app imported from the source already is its chart: there is nothing to choose
        if ($application->isHelmForge() && $application->chart_source_id === $source->id) {
            throw new RuntimeException("{$application->name} is already deployed from {$source->name}");
        }

        $chart_name ??= $application->slug;
        $secret = $source->helmRepoSecret?->requiresAuth() ? $source->helmRepoSecret : null;
        $entries = (new HttpChartRepository($source->url, '', $secret))->index()[$chart_name] ?? [];

        if (! $entries || ($entries[0]['type'] ?? 'application') === 'library') {
            throw new RuntimeException("{$source->name} has no application chart named {$chart_name}");
        }

        $repository = (new HttpChartRepository($source->url, $chart_name, $secret))->withEntries($entries);
        $latest = $entries[0];

        // The app needs a chart of its own first, so the new one is the other
        $application->defaultVersionGroup();

        $chart = $application->charts()->where('chart_source_id', $source->id)->where('is_default', false)->first();
        $created = ! $chart;
        $catalog = $chart?->catalog ?? [];

        // Detection needs the chart's values, so only download on a new version
        if (($catalog['chart_version'] ?? null) !== (string) $latest['version']) {
            $catalog = $this->catalog($chart_name, $latest, ChartPackage::fromArchive($repository->package((string) $latest['version'])));
        }

        $attributes = ['chart_name' => $chart_name, 'helm_repo' => $source->url, 'helm_repo_secret_id' => $source->helm_repo_secret_id, 'catalog' => $catalog];

        if ($created) {
            $chart = $application->charts()->create($attributes + [
                'name' => $source->name,
                'slug' => $this->uniqueChartSlug($application, $source->slug),
                'chart_source_id' => $source->id,
                'source' => ApplicationSource::HelmForge,
                'is_default' => false,
                'enabled' => false,
            ]);
        } else {
            $chart->update($attributes);
        }

        $skipped = [];
        $group = $chart->defaultVersionGroup();

        foreach (array_slice($entries, 0, (int) config('catalog.versions_per_chart', 3)) as $entry) {
            if ($reason = $this->syncChartVersion($chart, $group, $entry)) {
                $skipped[(string) $entry['version']] = $reason;
            }
        }

        return ['chart' => $chart, 'created' => $created, 'skipped' => $skipped];
    }

    private function uniqueChartSlug(Application $application, string $base): string
    {
        $taken = $application->charts()->pluck('slug')->all();
        $slug = Str::slug($base) ?: 'chart';
        $candidate = $slug;

        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $candidate = "{$slug}-{$n}";
        }

        return $candidate;
    }

    /**
     * Adds a version of an added chart to its group, unless it has it. Version names are
     * the app's own, so one the app already has under another chart can't be used.
     */
    private function syncChartVersion(AppChart $chart, AppVersionGroup $group, array $entry): ?string
    {
        $name = (string) $entry['version'];
        $existing = AppVersion::where('application_id', $chart->application_id)->where('name', $name)->first();

        if ($existing) {
            return $existing->versionGroup?->app_chart_id === $chart->id ? null : "the app already has a version named {$name}";
        }

        $version = new AppVersion;
        $version->application_id = $chart->application_id;
        $version->version_group_id = $group->id;
        $version->name = $name;
        $version->settings = [
            'chart_name' => $chart->chart_name,
            'chart_version' => $name,
            'helm_repo_name' => $chart->helm_repo,
        ];
        $version->helm_repo_secret_id = $chart->helm_repo_secret_id;
        $version->status = 'deactivated';
        $version->save();

        return null;
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
            return self::SHADOWED;
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

        $application->source = ApplicationSource::HelmForge;
        $application->chart_source_id = $source->id;
        $application->catalog = $catalog;
        $application->save();

        // Only seeded, never overwritten: an admin may have curated the tags
        if ($created || ! $application->tags()->exists()) {
            $application->tags()->sync(Tag::findOrCreateMany($this->tagNames($chart_name, $latest))->modelKeys());
        }

        foreach (array_slice($entries, 0, (int) config('catalog.versions_per_chart', 3)) as $entry) {
            $this->syncVersion($source, $application, $entry);
        }

        return ['created' => $created, 'missing' => $this->missingConcerns($catalog['convention'])];
    }

    /**
     * The chart's keywords and Artifact Hub category, minus the ones that say
     * nothing about what the app does (its own name, "kubernetes", ...).
     *
     * @return array<int, string>
     */
    private function tagNames(string $chart_name, array $entry): array
    {
        $category = $entry['annotations']['artifacthub.io/category'] ?? null;
        $names = array_merge($category === 'skip-prediction' ? [] : [$category], $entry['keywords'] ?? []);
        $ignored = array_map(Tag::slugFor(...), [...config('catalog.ignored_tags', []), $chart_name, Str::headline($chart_name)]);

        return array_values(array_filter(
            array_map('strval', array_filter($names)),
            fn ($name) => ! in_array(Tag::slugFor($name), $ignored, true),
        ));
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
