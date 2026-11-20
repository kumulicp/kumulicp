<?php

namespace App\Support\VersionUpdates;

use App\AppVersion;
use App\AppVersionImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Reads the chart a version uses for the images it runs, and proposes them as
 * images of the version. An image Kumulicp can write into the chart's values
 * can be kept as an override, which is then tested and checked for updates like
 * the rest of the version; any can be recorded as the chart's own, which keeps
 * a list of what the chart runs, and how it changes with the chart.
 */
class ChartImageDiscovery
{
    public function __construct(
        private ChartRepositoryResolver $repositories = new ChartRepositoryResolver,
        private ChartImageScanner $scanner = new ChartImageScanner,
    ) {}

    /**
     * What the version's chart runs, as proposals for images of the version.
     *
     * @return array<int, array{key: string, label: string, values_path: string, registry: string|null, repository: string, tag: string|null, digest: string|null, overridable: bool, exists: bool}>
     *
     * @throws RuntimeException when the chart can't be found or read
     */
    public function discover(AppVersion $version): array
    {
        $chart_name = $version->chartName();
        $chart_version = $version->setting('chart_version');

        if (! $chart_name || ! $chart_version || ! $version->helmRepo()) {
            throw new RuntimeException(__('admin.version_updates.discover_without_chart'));
        }

        try {
            ['repository' => $repository] = $this->repositories->resolve($version, $this->repositories->servers($version));
            $package = ChartPackage::fromArchive($repository->package((string) $chart_version));
        } catch (Throwable $exception) {
            throw new RuntimeException(__('admin.version_updates.discover_failed', ['error' => mb_strimwidth($exception->getMessage(), 0, 300, '…')]), 0, $exception);
        }

        return $this->proposals($version, $this->scanner->scan($package));
    }

    /**
     * Adds the images an admin picked from the proposals. A key the version
     * already has is left as it is.
     *
     * @param  array<int, array<string, mixed>>  $images  key, label, values_path, registry, repository, tag, digest and source (override or chart)
     * @return Collection<int, AppVersionImage> the ones that were added
     */
    public function adopt(AppVersion $version, array $images): Collection
    {
        $position = (int) $version->images()->max('position');
        $added = new Collection;

        foreach ($images as $image) {
            if ($version->images()->where('key', $image['key'])->exists()) {
                continue;
            }

            $added->push($version->images()->create([
                'key' => $image['key'],
                'label' => $image['label'] ?? null,
                'role' => AppVersionImage::ROLE_COMPONENT,
                'source' => $image['source'],
                'registry' => $image['registry'] ?? null,
                'repository' => $image['repository'],
                'tag' => $image['tag'] ?? null,
                'digest' => $image['digest'] ?? null,
                // An image with no place in the values (listed by the chart only) has no path
                'values_path' => $image['values_path'] ?? '',
                // A newer tag for an image the chart runs is for the chart to bring, not something to offer
                'update_policy' => $image['source'] === AppVersionImage::SOURCE_CHART ? 'ignore' : 'track',
                'position' => ++$position,
            ]));
        }

        $version->unsetRelation('images');

        return $added;
    }

    /**
     * @param  array<int, array<string, mixed>>  $scanned
     * @return array<int, array{key: string, label: string, values_path: string, registry: string|null, repository: string, tag: string|null, digest: string|null, overridable: bool, exists: bool}>
     */
    private function proposals(AppVersion $version, array $scanned): array
    {
        $existing = $version->images()->get();
        $taken = $existing->pluck('key')->all();
        $proposals = [];

        foreach ($scanned as $image) {
            // The image the version already has for the same place in the values, or the same repository
            $match = $existing->first(fn (AppVersionImage $own) => ($image['path'] !== '' && $own->values_path === $image['path'])
                || ($image['path'] === '' && $own->repository === $image['repository']));

            $key = $match->key ?? $this->key($image, $taken);
            $taken[] = $key;

            $proposals[] = [
                'key' => $key,
                'label' => $match?->label ?: ($image['label'] ?: Str::headline($key)),
                'values_path' => $image['path'],
                'registry' => $image['registry'],
                'repository' => $image['repository'],
                'tag' => $image['tag'],
                'digest' => $image['digest'],
                'overridable' => $image['overridable'],
                'exists' => $match !== null,
            ];
        }

        return $proposals;
    }

    /**
     * A key for the image that nothing else in the version has: its place in
     * the values without the "image" ("synapse.image" is synapse), or its name.
     *
     * @param  array<string, mixed>  $image
     * @param  array<int, string>  $taken
     */
    private function key(array $image, array $taken): string
    {
        $base = preg_replace('/(^|\.)image$/i', '', $image['path']);

        if ($base === '') {
            $base = $image['label'] ?: basename($image['repository']);
        }

        $key = Str::slug(str_replace('.', '-', $base)) ?: 'image';
        $candidate = $key;

        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $candidate = $key.'-'.$n;
        }

        return $candidate;
    }
}
