<?php

namespace App\Support\Volumes;

use InvalidArgumentException;

/**
 * What a chart says about one of its volumes (docs/volumes-and-settings-plan.md, section 1): which
 * workload owns it, how its size is decided and how it can be given a claim that already exists.
 * It says nothing about what an instance has chosen for it; that is Volume.
 *
 * The names are keys in the instance's settings, so they are fixed: renaming one needs a migration.
 */
final class VolumeSpec
{
    /** The chart takes one claim name, a StatefulSet makes its claims from a template, or the volume can't be given one */
    public const CLAIM_MODES = ['existing_claim', 'by_name', 'none'];

    /** The size follows the subscription's storage quota, or is its own */
    public const SIZE_POLICIES = ['quota', 'fixed'];

    /**
     * What a map says about where the chart keeps the volume in its values. Each key but `path`
     * is a field of the volume, and says where, relative to `path` (or from the root of the values
     * when there is none).
     *
     * - `access_mode_as` is 'list' for a chart that takes the modes as a list.
     * - `blank_claim` is what is written when there is no claim: '' unless the chart wants a null.
     * - `only_set` leaves out what the volume has no value for, so the chart keeps its own default,
     *   instead of writing it as blank.
     */
    public const MAP_KEYS = ['path', 'enabled', 'size', 'storage_class', 'access_mode', 'access_mode_as', 'claim', 'blank_claim', 'only_set'];

    // Names are used as settings keys, so no dots
    private const NAME = '/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/';

    public function __construct(
        public readonly string $workload,
        public readonly string $name,
        public readonly bool $primary = false,
        public readonly string $size_policy = 'fixed',
        public readonly int $default_size = 1,
        public readonly string $claim_mode = 'existing_claim',
        public readonly bool $optional = false,
        /** Where the chart keeps the volume in its values; none for a volume the chart writes itself */
        public readonly array $map = [],
    ) {
        foreach (['workload' => $workload, 'name' => $name] as $part => $value) {
            if (! preg_match(self::NAME, $value)) {
                throw new InvalidArgumentException("A volume's {$part} must be lowercase letters, digits and dashes: '{$value}'");
            }
        }

        if (! in_array($size_policy, self::SIZE_POLICIES, true)) {
            throw new InvalidArgumentException("Unknown size policy '{$size_policy}'");
        }

        if (! in_array($claim_mode, self::CLAIM_MODES, true)) {
            throw new InvalidArgumentException("Unknown claim mode '{$claim_mode}'");
        }

        if ($default_size < 1) {
            throw new InvalidArgumentException('A volume is at least 1Gi');
        }

        if ($unknown = array_diff(array_keys($map), self::MAP_KEYS)) {
            throw new InvalidArgumentException('A volume map has no '.implode(', ', $unknown));
        }

        if ($primary && $optional) {
            throw new InvalidArgumentException('The primary volume holds the app\'s files, so it can not be optional');
        }
    }

    /**
     * The volume every chart has to begin with: the one that holds the customer's files and
     * follows the storage quota.
     */
    public static function primary(): self
    {
        return new self('app', 'data', primary: true, size_policy: 'quota');
    }

    public function id(): string
    {
        return "{$this->workload}/{$this->name}";
    }
}
