<?php

namespace App\Support\VersionUpdates;

use App\RepoSecret;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * A classic Helm repository: an index.yaml next to the packaged charts.
 */
class HttpChartRepository implements ChartRepository
{
    private ?array $entries = null;

    public function __construct(
        private string $url,
        private string $chart_name = '',
        private ?RepoSecret $secret = null,
    ) {
        $this->url = rtrim($url, '/');
    }

    // Reuses index entries that were already fetched, so downloading many
    // charts from one repository doesn't re-fetch the whole index each time.
    public function withEntries(array $entries): static
    {
        $this->entries = $entries;

        return $this;
    }

    public function versions(): array
    {
        $versions = [];

        foreach ($this->entries() as $entry) {
            if (isset($entry['version'])) {
                $versions[(string) $entry['version']] = $entry;
            }
        }

        return $versions;
    }

    public function package(string $version): string
    {
        $entry = $this->versions()[$version] ?? null;
        $url = $entry['urls'][0] ?? null;

        if (! $url) {
            throw new RuntimeException(__('admin.version_updates.chart_version_missing', ['chart' => $this->chart_name, 'version' => $version]));
        }

        if (! preg_match('#^https?://#', $url)) {
            $url = $this->url.'/'.ltrim($url, '/');
        }

        $response = $this->request()->get($url);
        $response->throw();

        return $response->body();
    }

    /**
     * Every chart in the repository, keyed by chart name, each as the list of
     * its index entries (newest first, as Helm writes them).
     *
     * @return array<string, array<int, array>>
     */
    public function index(): array
    {
        $response = $this->request()->get($this->url.'/index.yaml');
        $response->throw();

        return (array) (Yaml::parse($response->body())['entries'] ?? []);
    }

    private function entries(): array
    {
        if ($this->entries === null) {
            $this->entries = (array) ($this->index()[$this->chart_name] ?? []);
        }

        return $this->entries;
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout(RegistryClient::TIMEOUT);

        if ($this->secret && $this->secret->requiresAuth()) {
            $request = $request->withBasicAuth($this->secret->username, (string) $this->secret->password);
        }

        return $request;
    }
}
