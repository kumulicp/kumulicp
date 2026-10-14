<?php

namespace App\Support\VersionUpdates;

use App\Server;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A chart in a Rancher ClusterRepo, read through Rancher's catalog API
 * (rancher/rancher pkg/api/steve/catalog/content.go):
 *
 *   GET /v1/catalog.cattle.io.clusterrepos/{repo}?link=index
 *       The repo index as JSON. Rancher filters out chart versions whose
 *       catalog.cattle.io/rancher-version annotation or kubeVersion don't
 *       match this Rancher and its cluster; skipFilter=true returns all.
 *   GET /v1/catalog.cattle.io.clusterrepos/{repo}?link=chart&chartName=&version=
 *       The packaged chart (.tgz).
 *
 * So the filtered index doubles as Rancher's own compatibility verdict.
 */
class RancherChartRepository implements ChartRepository
{
    private array $indexes = [];

    public function __construct(
        private Server $server,
        private string $repo_name,
        private string $chart_name,
    ) {}

    public function server(): Server
    {
        return $this->server;
    }

    // All versions, including ones Rancher would hide on this cluster.
    public function versions(): array
    {
        return $this->index(true);
    }

    // Only the versions Rancher accepts for this Rancher and cluster version.
    public function compatibleVersions(): array
    {
        return $this->index(false);
    }

    public function package(string $version): string
    {
        $response = $this->request()->get($this->url(), [
            'link' => 'chart',
            'chartName' => $this->chart_name,
            'version' => $version,
        ]);
        $response->throw();

        return $response->body();
    }

    private function index(bool $skip_filter): array
    {
        $key = $skip_filter ? 'all' : 'filtered';

        if (! isset($this->indexes[$key])) {
            $query = ['link' => 'index'];
            if ($skip_filter) {
                $query['skipFilter'] = 'true';
            }

            $response = $this->request()->get($this->url(), $query);
            $response->throw();

            $versions = [];
            foreach ((array) ($response->json('entries')[$this->chart_name] ?? []) as $entry) {
                if (isset($entry['version'])) {
                    $versions[(string) $entry['version']] = $entry;
                }
            }

            $this->indexes[$key] = $versions;
        }

        return $this->indexes[$key];
    }

    private function url(): string
    {
        if (! $this->server->address) {
            throw new RuntimeException(__('admin.version_updates.server_missing_address', ['server' => $this->server->name]));
        }

        return rtrim($this->server->address, '/').'/v1/catalog.cattle.io.clusterrepos/'.rawurlencode($this->repo_name);
    }

    private function request(): PendingRequest
    {
        return Http::timeout(RegistryClient::TIMEOUT)
            ->withOptions(['verify' => ! app()->environment('local', 'testing')])
            ->withBasicAuth((string) $this->server->api_key, (string) $this->server->api_secret);
    }
}
