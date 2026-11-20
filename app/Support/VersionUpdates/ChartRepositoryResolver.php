<?php

namespace App\Support\VersionUpdates;

use App\AppInstance;
use App\AppVersion;
use App\Server;
use RuntimeException;

/**
 * Where a version's chart is published, as the update check and the discovery of
 * its images reach it: an HTTP repository, an OCI registry, or a Rancher
 * ClusterRepo, which only a Rancher server running the app can read.
 */
class ChartRepositoryResolver
{
    /**
     * @param  array<int, Server>  $servers  see servers()
     * @return array{repository: ChartRepository, rancher: array<int, RancherChartRepository>} one Rancher repository per server for a ClusterRepo, which are what is checked against each
     *
     * @throws RuntimeException when no server can reach a ClusterRepo
     */
    public function resolve(AppVersion $version, array $servers): array
    {
        $chart_name = (string) $version->chartName();
        $repo = (string) $version->helmRepo();
        $secret = $version->requiresHelmRepoAuth() ? $version->chartRepoSecret() : null;

        if (preg_match('#^https?://#', $repo)) {
            return ['repository' => new HttpChartRepository($repo, $chart_name, $secret), 'rancher' => []];
        }

        if (str_starts_with($repo, 'oci://')) {
            return ['repository' => new OciChartRepository($repo, $chart_name, $secret), 'rancher' => []];
        }

        // A Rancher ClusterRepo name: only reachable through Rancher
        $rancher_repositories = [];
        foreach ($servers as $server) {
            if ($server->interface === 'rancher') {
                $rancher_repositories[] = new RancherChartRepository($server, $repo, $chart_name);
            }
        }

        if (! $rancher_repositories) {
            throw new RuntimeException(__('admin.version_updates.cluster_repo_without_rancher', ['repo' => $repo]));
        }

        return ['repository' => $rancher_repositories[0], 'rancher' => $rancher_repositories];
    }

    /**
     * The distinct servers running instances of this version's app.
     *
     * @return array<int, Server>
     */
    public function servers(AppVersion $version): array
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
}
