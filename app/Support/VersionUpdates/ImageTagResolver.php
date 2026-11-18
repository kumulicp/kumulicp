<?php

namespace App\Support\VersionUpdates;

use App\AppVersionImage;
use Throwable;

/**
 * Lists the tags published for one of a version's container images, using the
 * same registry and pull secret the charts deploy it with (see Chart::image()).
 *
 * What a registry answers is kept for as long as the resolver lives, so one
 * run over many versions asks about a repository once, and a registry that is
 * down is only waited for once.
 */
class ImageTagResolver
{
    /** @var array<string, array{tags: array<int, string>, error: Throwable|null}> */
    private array $tags = [];

    /**
     * @return array<int, string>
     */
    public function tags(AppVersionImage $image): array
    {
        [$host, $repository] = $this->location($image);
        $key = $host.'|'.$repository.'|'.($image->requiresAuth() ? $image->registry_secret_id : 0);

        if (! isset($this->tags[$key])) {
            try {
                $this->tags[$key] = ['tags' => $this->client($image)->tags($host, $repository), 'error' => null];
            } catch (Throwable $exception) {
                $this->tags[$key] = ['tags' => [], 'error' => $exception];
            }
        }

        if ($this->tags[$key]['error'] !== null) {
            throw $this->tags[$key]['error'];
        }

        return $this->tags[$key]['tags'];
    }

    /**
     * The digest the tag points at, null when the registry doesn't say.
     */
    public function digest(AppVersionImage $image, string $tag): ?string
    {
        [$host, $repository] = $this->location($image);

        return $this->client($image)->digest($host, $repository, $tag);
    }

    /**
     * The registry's API host and the repository on it.
     *
     * @return array{0: string, 1: string}
     */
    private function location(AppVersionImage $image): array
    {
        $repository = $image->repository;
        $registry = $image->effectiveRegistry();

        // Allow a fully qualified repo ("ghcr.io/org/app") when no registry is set
        $first_segment = explode('/', $repository)[0];
        if (! $registry && str_contains($repository, '/') && (str_contains($first_segment, '.') || str_contains($first_segment, ':'))) {
            $registry = $first_segment;
            $repository = substr($repository, strlen($first_segment) + 1);
        }

        $host = RegistryClient::apiHost($registry);

        return [$host, RegistryClient::repository($host, $repository)];
    }

    private function client(AppVersionImage $image): RegistryClient
    {
        return new RegistryClient($image->requiresAuth() ? $image->registrySecret : null);
    }
}
