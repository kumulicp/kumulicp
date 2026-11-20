<?php

namespace App\Support\VersionUpdates;

use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Finds the container images a chart runs, without helm, in what the packaged
 * chart tells about them:
 *
 *  - blocks in the default values.yaml that say where an image comes from, as
 *    nearly every chart does: `<path>: {registry, repository, tag, digest}`. These
 *    can be written to by Kumulicp (overridable);
 *  - values that hold a whole reference (`proxy.image: ghcr.io/org/proxy:1.2`)
 *    under a key named image, which can only be recorded;
 *  - the images a chart lists in its Chart.yaml `artifacthub.io/images`
 *    annotation that are not in the values, which have no path at all.
 *
 * Only the chart's own values are read, not its subcharts', and anything that
 * is templated ("{{ ... }}") is skipped.
 */
class ChartImageScanner
{
    private const MAX_DEPTH = 8;

    /**
     * @return array<int, array{path: string, registry: string|null, repository: string, tag: string|null, digest: string|null, label: string|null, overridable: bool}>
     */
    public function scan(ChartPackage $package): array
    {
        $images = [];

        $this->walk($package->values, '', 0, $images);

        foreach ($this->annotated($package) as $image) {
            if (! $this->listed($images, $image)) {
                $images[] = $image;
            }
        }

        return $images;
    }

    /**
     * The scanned images that have a place in the values, by that path.
     *
     * @param  array<int, array<string, mixed>>  $images
     * @return array<string, array<string, mixed>>
     */
    public static function byPath(array $images): array
    {
        $by_path = [];

        foreach ($images as $image) {
            if ($image['path'] !== '') {
                $by_path[$image['path']] = $image;
            }
        }

        return $by_path;
    }

    /**
     * The reference an image comes down to: registry/repository:tag@digest.
     *
     * @param  array<string, mixed>  $image
     */
    public static function reference(array $image): string
    {
        $reference = ($image['registry'] ? $image['registry'].'/' : '').$image['repository'];

        if ($image['tag']) {
            $reference .= ':'.$image['tag'];
        }

        if ($image['digest']) {
            $reference .= '@'.$image['digest'];
        }

        return $reference;
    }

    /**
     * Which image it is across chart versions: where it goes in the values, or for
     * one that doesn't go anywhere, what it is.
     *
     * @param  array<string, mixed>  $image
     */
    public static function identity(array $image): string
    {
        return $image['path'] !== '' ? $image['path'] : '@'.self::repositoryName($image);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, array<string, mixed>>  $images
     */
    private function walk(array $node, string $path, int $depth, array &$images): void
    {
        // Lists (containers, hosts, ...) have no path to write to
        if ($depth > self::MAX_DEPTH || ($node !== [] && array_is_list($node))) {
            return;
        }

        if ($path !== '' && $this->isImageBlock($node)) {
            $images[] = [
                'path' => $path,
                'registry' => $this->text($node['registry'] ?? null),
                'repository' => trim((string) $node['repository']),
                'tag' => $this->text($node['tag'] ?? null),
                'digest' => $this->text($node['digest'] ?? null),
                'label' => null,
                'overridable' => true,
            ];

            return;
        }

        foreach ($node as $key => $value) {
            $key = (string) $key;

            // A dot in a key can't be told apart from a path
            if ($key === '' || str_contains($key, '.')) {
                continue;
            }

            $child = $path === '' ? $key : $path.'.'.$key;

            if (is_array($value)) {
                $this->walk($value, $child, $depth + 1, $images);
            } elseif (is_string($value) && $this->isImageKey($key) && ($image = $this->parse($value, true))) {
                $images[] = $image + ['path' => $child, 'label' => null, 'overridable' => false];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function isImageBlock(array $node): bool
    {
        $repository = $node['repository'] ?? null;

        if (! is_string($repository) || trim($repository) === '' || str_contains($repository, '{{') || str_contains($repository, '://') || preg_match('/\s/', trim($repository))) {
            return false;
        }

        // A repository alone could be anything (a git repository, a chart repository)
        return array_key_exists('tag', $node) || array_key_exists('digest', $node) || array_key_exists('registry', $node);
    }

    private function isImageKey(string $key): bool
    {
        return $key === 'image' || str_ends_with($key, 'Image') || str_ends_with($key, '-image') || str_ends_with($key, '_image');
    }

    /**
     * The images the chart lists in its annotation.
     *
     * @return array<int, array{path: string, registry: string|null, repository: string, tag: string|null, digest: string|null, label: string|null, overridable: bool}>
     */
    private function annotated(ChartPackage $package): array
    {
        $raw = $package->annotation('artifacthub.io/images');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            $entries = Yaml::parse($raw);
        } catch (Throwable) {
            return [];
        }

        $images = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['image'] ?? null) || ! ($image = $this->parse($entry['image'], false))) {
                continue;
            }

            $images[] = $image + ['path' => '', 'label' => is_string($entry['name'] ?? null) ? $entry['name'] : null, 'overridable' => false];
        }

        return $images;
    }

    /**
     * Whether an annotated image is one the values already have.
     *
     * @param  array<int, array<string, mixed>>  $images
     * @param  array<string, mixed>  $annotated
     */
    private function listed(array $images, array $annotated): bool
    {
        foreach ($images as $image) {
            if (self::repositoryName($image) === self::repositoryName($annotated)) {
                return true;
            }
        }

        return false;
    }

    /**
     * registry/repository, with the Docker Hub's spellings made the same.
     *
     * @param  array<string, mixed>  $image
     */
    private static function repositoryName(array $image): string
    {
        $name = strtolower(($image['registry'] ? $image['registry'].'/' : '').$image['repository']);
        $name = preg_replace('#^(index\.)?docker\.io/#', '', $name);

        return preg_replace('#^library/#', '', $name);
    }

    /**
     * A reference ("ghcr.io/org/app:1.2@sha256:...") in its parts, or null when it
     * isn't one. A strict parse wants more than a bare word, which under a key
     * named image could be anything.
     *
     * @return array{registry: string|null, repository: string, tag: string|null, digest: string|null}|null
     */
    private function parse(string $reference, bool $strict): ?array
    {
        $reference = trim($reference);

        if ($reference === '' || str_contains($reference, '{{') || preg_match('/\s/', $reference) || str_contains($reference, '://')) {
            return null;
        }

        if ($strict && ! preg_match('/[\/:@]/', $reference)) {
            return null;
        }

        $digest = null;
        if (str_contains($reference, '@')) {
            [$reference, $digest] = explode('@', $reference, 2);
        }

        $tag = null;
        $colon = strrpos($reference, ':');
        $slash = strrpos($reference, '/');
        if ($colon !== false && ($slash === false || $colon > $slash)) {
            $tag = substr($reference, $colon + 1);
            $reference = substr($reference, 0, $colon);
        }

        $parts = explode('/', $reference);
        $registry = null;
        if (count($parts) > 1 && (str_contains($parts[0], '.') || str_contains($parts[0], ':') || $parts[0] === 'localhost')) {
            $registry = array_shift($parts);
        }

        $repository = implode('/', $parts);

        if ($repository === '') {
            return null;
        }

        return ['registry' => $registry, 'repository' => $repository, 'tag' => $this->text($tag), 'digest' => $this->text($digest)];
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
