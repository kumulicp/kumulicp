<?php

namespace App\Support\VersionUpdates;

use App\RepoSecret;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal OCI / Docker Registry v2 client: tag listing, manifests and blobs.
 * Handles the anonymous or credentialed bearer-token challenge used by
 * Docker Hub, ghcr.io, quay.io and most self-hosted registries, plus plain
 * basic auth. Used both for container images and for OCI-hosted charts.
 */
class RegistryClient
{
    public const TIMEOUT = 15;

    private const MAX_TAG_PAGES = 20;

    /** @var array<string, string> */
    private array $tokens = [];

    public function __construct(private ?RepoSecret $secret = null) {}

    // Docker Hub's API host differs from the name people write in image refs.
    public static function apiHost(?string $registry): string
    {
        $registry = trim((string) $registry);
        $registry = preg_replace('#^(https?|oci)://#', '', $registry);
        $registry = rtrim($registry, '/');

        if (in_array($registry, ['', 'docker.io', 'index.docker.io', 'registry.hub.docker.com'], true)) {
            return 'registry-1.docker.io';
        }

        return $registry;
    }

    // A bare Docker Hub repo such as "nextcloud" is really "library/nextcloud".
    public static function repository(string $host, string $repository): string
    {
        $repository = trim($repository, '/');

        if ($host === 'registry-1.docker.io' && ! str_contains($repository, '/')) {
            return 'library/'.$repository;
        }

        return $repository;
    }

    /**
     * @return array<int, string>
     */
    public function tags(string $host, string $repository): array
    {
        $tags = [];
        $url = "https://{$host}/v2/{$repository}/tags/list?n=1000";

        for ($page = 0; $url && $page < self::MAX_TAG_PAGES; $page++) {
            $response = $this->get($url, $repository);
            $response->throw();

            $tags = array_merge($tags, (array) ($response->json('tags') ?? []));
            $url = $this->nextPage($host, $response->header('Link'));
        }

        return $tags;
    }

    public function manifest(string $host, string $repository, string $reference): array
    {
        $response = $this->get("https://{$host}/v2/{$repository}/manifests/{$reference}", $repository, [
            'Accept' => 'application/vnd.oci.image.manifest.v1+json',
        ]);
        $response->throw();

        return (array) $response->json();
    }

    public function blob(string $host, string $repository, string $digest): string
    {
        $response = $this->get("https://{$host}/v2/{$repository}/blobs/{$digest}", $repository);
        $response->throw();

        return $response->body();
    }

    private function get(string $url, string $repository, array $headers = []): Response
    {
        $token = $this->tokens[$repository] ?? null;
        $response = $this->request($token)->withHeaders($headers)->get($url);

        if ($response->status() !== 401) {
            return $response;
        }

        $challenge = $response->header('WWW-Authenticate');

        if (stripos($challenge, 'bearer') === 0) {
            $token = $this->bearerToken($challenge, $repository);

            return $this->request($token)->withHeaders($headers)->get($url);
        }

        if (stripos($challenge, 'basic') === 0 && $this->hasCredentials()) {
            return $this->request()->withBasicAuth($this->secret->username, (string) $this->secret->password)->withHeaders($headers)->get($url);
        }

        return $response;
    }

    private function request(?string $token = null): PendingRequest
    {
        $request = Http::timeout(self::TIMEOUT)->acceptJson();

        return $token ? $request->withToken($token) : $request;
    }

    private function bearerToken(string $challenge, string $repository): ?string
    {
        preg_match_all('/(\w+)="([^"]*)"/', $challenge, $matches, PREG_SET_ORDER);
        $params = [];
        foreach ($matches as $match) {
            $params[strtolower($match[1])] = $match[2];
        }

        if (empty($params['realm'])) {
            return null;
        }

        $query = array_filter([
            'service' => $params['service'] ?? null,
            'scope' => $params['scope'] ?? "repository:{$repository}:pull",
        ]);

        $request = Http::timeout(self::TIMEOUT)->acceptJson();
        if ($this->hasCredentials()) {
            $request = $request->withBasicAuth($this->secret->username, (string) $this->secret->password);
        }

        $response = $request->get($params['realm'], $query);
        $response->throw();

        $token = $response->json('token') ?? $response->json('access_token');

        return $this->tokens[$repository] = $token;
    }

    private function hasCredentials(): bool
    {
        return $this->secret !== null && $this->secret->requiresAuth();
    }

    private function nextPage(string $host, string $link): ?string
    {
        if (! preg_match('/<([^>]+)>\s*;\s*rel="?next"?/', $link, $matches)) {
            return null;
        }

        $next = $matches[1];

        return str_starts_with($next, 'http') ? $next : "https://{$host}".$next;
    }
}
