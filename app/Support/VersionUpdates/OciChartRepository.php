<?php

namespace App\Support\VersionUpdates;

use App\RepoSecret;
use RuntimeException;

/**
 * A chart stored in an OCI registry (oci://host/path/<chart>), read through
 * the registry HTTP API. Helm stores "+" in chart versions as "_" in tags.
 */
class OciChartRepository implements ChartRepository
{
    private const CHART_LAYER = 'application/vnd.cncf.helm.chart.content.v1.tar+gzip';

    private RegistryClient $client;

    private string $host;

    private string $repository;

    public function __construct(string $url, string $chart_name, ?RepoSecret $secret = null)
    {
        $reference = preg_replace('#^oci://#', '', rtrim($url, '/'));
        [$host, $path] = array_pad(explode('/', $reference, 2), 2, '');

        $this->host = RegistryClient::apiHost($host);
        $this->repository = trim($path.'/'.$chart_name, '/');
        $this->client = new RegistryClient($secret);
    }

    public function versions(): array
    {
        $versions = [];

        foreach ($this->client->tags($this->host, $this->repository) as $tag) {
            $version = str_replace('_', '+', $tag);
            $versions[$version] = ['version' => $version];
        }

        return $versions;
    }

    public function package(string $version): string
    {
        $manifest = $this->client->manifest($this->host, $this->repository, str_replace('+', '_', $version));

        foreach ($manifest['layers'] ?? [] as $layer) {
            if (($layer['mediaType'] ?? null) === self::CHART_LAYER) {
                return $this->client->blob($this->host, $this->repository, $layer['digest']);
            }
        }

        throw new RuntimeException(__('admin.version_updates.chart_version_missing', ['chart' => $this->repository, 'version' => $version]));
    }
}
