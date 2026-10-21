<?php

namespace App\Support\VersionUpdates;

use App\AppVersion;

/**
 * Lists the tags published for a version's container image, using the same
 * image registry the charts deploy it with (see Chart::image()).
 */
class ImageTagResolver
{
    /**
     * @return array<int, string>|null null when the version has no image configured
     */
    public function tags(AppVersion $version): ?array
    {
        $repository = $version->setting('image_repo_name');

        if (! $repository) {
            return null;
        }

        $registry = $version->imageRegistry?->registry ?: $version->setting('image_registry');

        // Allow a fully qualified repo ("ghcr.io/org/app") when no registry is set
        $first_segment = explode('/', $repository)[0];
        if (! $registry && str_contains($repository, '/') && (str_contains($first_segment, '.') || str_contains($first_segment, ':'))) {
            $registry = $first_segment;
            $repository = substr($repository, strlen($first_segment) + 1);
        }

        $host = RegistryClient::apiHost($registry);
        $client = new RegistryClient($version->requiresImageRegistryAuth() ? $version->imageRegistry : null);

        return $client->tags($host, RegistryClient::repository($host, $repository));
    }
}
