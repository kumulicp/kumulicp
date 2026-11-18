<?php

namespace App\Support;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use InvalidArgumentException;

/**
 * The version of the Kumuli CP core.
 *
 * The version is defined in exactly one place: the VERSION file in the project
 * root (exposed as config('app.version')). Plugins/modules should read it
 * through this class instead of parsing the file or config themselves:
 *
 *     CoreVersion::current();                  // "0.5.0"
 *     CoreVersion::satisfies('^0.5');          // composer-style constraint
 *     CoreVersion::atLeast('0.5.2');
 *
 * Releases are tagged vX.Y.Z and the release workflow refuses to publish when
 * the tag does not match the VERSION file.
 */
class CoreVersion
{
    /**
     * The core version as a plain "X.Y.Z" string (a leading "v" is removed).
     */
    public static function current(): string
    {
        $version = ltrim(trim((string) config('app.version')), 'vV');

        if ($version === '') {
            throw new InvalidArgumentException('The core version is not set. Check the VERSION file in the project root.');
        }

        return $version;
    }

    /**
     * Whether the core version satisfies a composer-style constraint such as
     * "^0.5", ">=0.5.1 <0.7" or "0.5.*".
     */
    public static function satisfies(string $constraint): bool
    {
        return Semver::satisfies(self::current(), $constraint);
    }

    /**
     * Whether the core version is greater than or equal to the given version.
     */
    public static function atLeast(string $version): bool
    {
        return Comparator::greaterThanOrEqualTo(self::current(), self::normalize($version));
    }

    /**
     * Whether the core version is strictly lower than the given version.
     */
    public static function lessThan(string $version): bool
    {
        return Comparator::lessThan(self::current(), self::normalize($version));
    }

    /**
     * Compare the core version with another: -1, 0 or 1 (like version_compare).
     */
    public static function compare(string $version): int
    {
        return version_compare(
            (new VersionParser)->normalize(self::current()),
            (new VersionParser)->normalize(self::normalize($version)),
        );
    }

    public static function major(): int
    {
        return (int) explode('.', self::current())[0];
    }

    public static function minor(): int
    {
        return (int) (explode('.', self::current())[1] ?? 0);
    }

    public static function patch(): int
    {
        return (int) (explode('.', self::current())[2] ?? 0);
    }

    private static function normalize(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }
}
