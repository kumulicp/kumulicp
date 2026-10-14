<?php

namespace App\Support\VersionUpdates;

use App\AppInstance;
use App\AppVersion;
use App\Server;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Looks for a newer image tag and chart version for one app version and
 * stores the result in app_versions.update_check. A newer chart is only
 * offered as an upgrade once it passes ChartCompatibilityChecker; the
 * newest release is still recorded, with the reasons it failed.
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
        $result = [
            'checked_at' => Carbon::now()->toIso8601String(),
            'image' => $this->checkImage($version),
            'chart' => $this->checkChart($version),
        ];

        $version->update_check = $result;
        $version->save();

        return $result;
    }

    private function checkImage(AppVersion $version): ?array
    {
        $current = (string) $version->name;

        try {
            $tags = $this->images->tags($version);
        } catch (Throwable $exception) {
            return ['current' => $current, 'latest' => null, 'error' => $this->message($exception)];
        }

        if ($tags === null) {
            return null;
        }

        return ['current' => $current, 'latest' => $this->comparator->newest($current, $tags), 'error' => null];
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
