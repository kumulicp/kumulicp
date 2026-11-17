<?php

namespace App\Support\VersionUpdates;

use App\AppInstance;
use App\AppVersion;
use App\AppVersionImage;
use App\Server;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Looks for a newer tag for each of an app version's images and a newer chart
 * version, and stores the result in app_versions.update_check. A newer chart is
 * only offered as an upgrade once it passes ChartCompatibilityChecker; the
 * newest release is still recorded, with the reasons it failed.
 *
 * The images are in `images`, by key. `image` is the primary one again, which is
 * what a version with a single image has always had there.
 */
class VersionUpdateChecker
{
    // How many newer chart versions to try when the newest one fails
    public const MAX_CANDIDATES = 5;

    public function __construct(
        private VersionComparator $comparator = new VersionComparator,
        private ImageTagResolver $images = new ImageTagResolver,
        private ChartCompatibilityChecker $compatibility = new ChartCompatibilityChecker,
        private ValuesContract $contract = new ValuesContract,
    ) {}

    public function check(AppVersion $version): array
    {
        $images = $this->checkImages($version);

        $result = [
            'checked_at' => Carbon::now()->toIso8601String(),
            'image' => $images[AppVersionImage::PRIMARY_KEY] ?? null,
            'images' => $images,
            'chart' => $this->checkChart($version),
        ];

        $version->update_check = $result;
        $version->save();

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>> by the image's key
     */
    private function checkImages(AppVersion $version): array
    {
        // The primary image follows the version's settings, which can have been changed without it noticing
        $version->syncLegacyPrimaryImage();

        $results = [];

        foreach ($version->images()->with('registrySecret')->get() as $image) {
            $image->setRelation('version', $version);
            $results[$image->key] = $this->checkImage($image);
        }

        return $results;
    }

    /**
     * @return array{current: string|null, latest: string|null, policy: string, error: string|null, digest?: string}
     */
    private function checkImage(AppVersionImage $image): array
    {
        $current = (string) $image->effectiveTag();
        $result = ['current' => $current !== '' ? $current : null, 'latest' => null, 'policy' => $image->update_policy, 'error' => null];

        // Nothing to compare with, or nothing to offer: no need to ask the registry
        if ($current === '' || in_array($image->update_policy, ['pinned', 'ignore'], true)) {
            return $result;
        }

        try {
            $tags = array_map('strval', $this->images->tags($image));

            if ($image->tag_pattern) {
                $pattern = $this->tagPattern($image->tag_pattern);
                $tags = array_values(array_filter($tags, fn (string $tag) => preg_match($pattern, $tag) === 1));
            }

            $latest = $this->comparator->newestFor($current, $tags, $image->update_policy);

            // An image kept by digest is only offered with the digest of the new tag, never the tag alone
            if ($latest !== null && $image->digest) {
                $digest = $this->images->digest($image, $latest);

                if (! $digest) {
                    throw new RuntimeException(__('admin.version_updates.digest_unavailable', ['tag' => $latest]));
                }

                $result['digest'] = $digest;
            }

            $result['latest'] = $latest;
        } catch (Throwable $exception) {
            $result['error'] = $this->message($exception);
        }

        return $result;
    }

    private function tagPattern(string $pattern): string
    {
        $regex = '/'.str_replace('/', '\\/', $pattern).'/';

        if (@preg_match($regex, '') === false) {
            throw new RuntimeException(__('admin.version_updates.invalid_tag_pattern', ['pattern' => $pattern]));
        }

        return $regex;
    }

    private function checkChart(AppVersion $version): ?array
    {
        $chart_name = $version->setting('chart_name');
        $current = $version->setting('chart_version');
        $repo = $version->setting('helm_repo_name');

        if (! $chart_name || ! $current || ! $repo) {
            return null;
        }

        $current = (string) $current;
        $result = ['current' => $current, 'latest' => null, 'latest_compatible' => null, 'compatibility' => null, 'error' => null];

        $servers = $this->servers($version);
        $rancher_repositories = [];
        $secret = $version->requiresHelmRepoAuth() ? $version->helmRepoSecret : null;

        if (preg_match('#^https?://#', $repo)) {
            $repository = new HttpChartRepository($repo, $chart_name, $secret);
        } elseif (str_starts_with($repo, 'oci://')) {
            $repository = new OciChartRepository($repo, $chart_name, $secret);
        } else {
            // A Rancher ClusterRepo name: only reachable through Rancher
            foreach ($servers as $server) {
                if ($server->interface === 'rancher') {
                    $rancher_repositories[] = new RancherChartRepository($server, $repo, $chart_name);
                }
            }

            if (! $rancher_repositories) {
                return [...$result, 'error' => __('admin.version_updates.cluster_repo_without_rancher', ['repo' => $repo])];
            }

            $repository = $rancher_repositories[0];
        }

        try {
            $candidates = $this->comparator->newer($current, array_map('strval', array_keys($repository->versions())));
        } catch (Throwable $exception) {
            return [...$result, 'error' => $this->message($exception)];
        }

        if (! $candidates) {
            return $result;
        }

        $result['latest'] = $candidates[0];
        $rendered_values = $this->contract->render($version);

        foreach (array_slice($candidates, 0, self::MAX_CANDIDATES) as $index => $candidate) {
            $compatibility = $this->compatibility->check($version, $repository, $candidate, $servers, $rancher_repositories, $rendered_values);

            if ($index === 0) {
                $result['compatibility'] = $compatibility;
            }

            if ($compatibility['status'] === ChartCompatibilityChecker::COMPATIBLE) {
                $result['latest_compatible'] = $candidate;
                break;
            }
        }

        return $result;
    }

    /**
     * The distinct servers running instances of this version's app.
     *
     * @return array<int, Server>
     */
    private function servers(AppVersion $version): array
    {
        return AppInstance::where('application_id', $version->application_id)
            ->notDeactivated()
            ->whereNotNull('web_server_id')
            ->with('web_server.server')
            ->get()
            ->map(fn (AppInstance $app_instance) => $app_instance->web_server?->server)
            ->filter()
            ->unique('id')
            ->values()
            ->all();
    }

    private function message(Throwable $exception): string
    {
        return mb_strimwidth($exception->getMessage(), 0, 300, '…');
    }
}
