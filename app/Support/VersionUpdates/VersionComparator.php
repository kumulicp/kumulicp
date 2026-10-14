<?php

namespace App\Support\VersionUpdates;

/**
 * Picks upgrade candidates for an image tag or chart version out of a list
 * of tags published upstream.
 *
 * Only tags with the same "shape" as the current one are considered: the
 * same number of dot-separated version parts and the same suffix once its
 * digits are ignored. So 8.0.1-debian-12-r1 is only compared with other
 * N.N.N-debian-N-rN tags, and floating tags such as latest, 33, 33-apache
 * or alpine never count as an upgrade for 33.0.1. Pre-releases are skipped
 * unless the current version is itself a pre-release.
 */
class VersionComparator
{
    private const PRERELEASE_PATTERN = '/(alpha|beta|rc|dev|pre|preview|snapshot|nightly|canary)/i';

    /**
     * Every tag newer than $current with the same shape, newest first.
     *
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    public function newer(string $current, array $tags): array
    {
        $current_shape = $this->shape($current);

        if ($current_shape === null) {
            return [];
        }

        $allow_prerelease = $this->isPrerelease($current);

        $candidates = array_values(array_unique(array_filter($tags, function ($tag) use ($current, $current_shape, $allow_prerelease) {
            if (! is_string($tag) || $this->shape($tag) !== $current_shape) {
                return false;
            }

            if (! $allow_prerelease && $this->isPrerelease($tag)) {
                return false;
            }

            return $this->compare($tag, $current) > 0;
        })));

        usort($candidates, fn ($a, $b) => $this->compare($b, $a));

        return $candidates;
    }

    // The newest tag newer than $current, or null when $current is the newest.
    public function newest(string $current, array $tags): ?string
    {
        return $this->newer($current, $tags)[0] ?? null;
    }

    public function isNewer(?string $candidate, ?string $current): bool
    {
        if (! $candidate || ! $current) {
            return false;
        }

        return $this->shape($candidate) !== null
            && $this->shape($candidate) === $this->shape($current)
            && $this->compare($candidate, $current) > 0;
    }

    public function compare(string $a, string $b): int
    {
        $a_numbers = $this->numbers($a);
        $b_numbers = $this->numbers($b);

        foreach ($a_numbers as $index => $number) {
            if (! array_key_exists($index, $b_numbers)) {
                return 1;
            }

            if ($number !== $b_numbers[$index]) {
                return $number <=> $b_numbers[$index];
            }
        }

        return count($a_numbers) < count($b_numbers) ? -1 : 0;
    }

    public function isPrerelease(string $tag): bool
    {
        $suffix = preg_replace('/^v?\d+(\.\d+)*/', '', $tag);

        return (bool) preg_match(self::PRERELEASE_PATTERN, $suffix);
    }

    private function shape(string $tag): ?string
    {
        if (! preg_match('/^(v?)(\d+(?:\.\d+)*)(.*)$/', $tag, $matches)) {
            return null;
        }

        $parts = substr_count($matches[2], '.') + 1;
        $suffix = preg_replace('/\d+/', '#', $matches[3]);

        return $matches[1].$parts.'|'.$suffix;
    }

    /**
     * @return array<int, int>
     */
    private function numbers(string $tag): array
    {
        preg_match_all('/\d+/', $tag, $matches);

        return array_map('intval', $matches[0]);
    }
}
