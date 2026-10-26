<?php

namespace App\Support\Security;

/**
 * A named set of Pod Security Admission levels that a base plan can select.
 * Pure value object: no framework dependencies.
 */
class SecurityTier
{
    public const NONE = 'none';

    public const LEVELS = ['privileged', 'baseline', 'restricted'];

    public const LABEL_PREFIX = 'pod-security.kubernetes.io/';

    public const MODES = ['enforce', 'warn', 'audit'];

    public function __construct(
        public readonly string $key,
        public readonly ?string $label = null,
        public readonly ?string $enforce = null,
        public readonly ?string $warn = null,
        public readonly ?string $audit = null,
        public readonly string $enforceVersion = 'latest',
        public readonly string $warnVersion = 'latest',
        public readonly string $auditVersion = 'latest',
        public readonly bool $preset = false,
    ) {}

    /**
     * Presets always exist and can't be edited or removed. Selecting none of
     * them (the default) leaves every namespace exactly as it is today.
     *
     * @return array<string, SecurityTier>
     */
    public static function presets(): array
    {
        return [
            self::NONE => new self(self::NONE, preset: true),
            'observe' => new self('observe', warn: 'restricted', audit: 'restricted', preset: true),
            'baseline' => new self('baseline', enforce: 'baseline', warn: 'restricted', audit: 'restricted', preset: true),
            'restricted' => new self('restricted', enforce: 'restricted', warn: 'restricted', audit: 'restricted', preset: true),
        ];
    }

    /**
     * Builds a tier from stored/user-supplied data, dropping anything invalid
     * rather than failing, so a bad stored value degrades to "no policy".
     */
    public static function fromArray(string $key, array $data): self
    {
        return new self(
            key: $key,
            label: isset($data['label']) && is_string($data['label']) && $data['label'] !== '' ? $data['label'] : null,
            enforce: self::level($data['enforce'] ?? null),
            warn: self::level($data['warn'] ?? null),
            audit: self::level($data['audit'] ?? null),
            enforceVersion: self::version($data['enforce_version'] ?? null),
            warnVersion: self::version($data['warn_version'] ?? null),
            auditVersion: self::version($data['audit_version'] ?? null),
        );
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'enforce' => $this->enforce,
            'warn' => $this->warn,
            'audit' => $this->audit,
            'enforce_version' => $this->enforceVersion,
            'warn_version' => $this->warnVersion,
            'audit_version' => $this->auditVersion,
        ];
    }

    public static function level(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::LEVELS, true) ? $value : null;
    }

    // 'latest', or a Kubernetes minor version such as v1.30
    public static function version(mixed $value): string
    {
        return is_string($value) && preg_match('/^(latest|v1\.\d{1,3})$/', $value) ? $value : 'latest';
    }

    // Strictness of a level: -1 for none, then privileged < baseline < restricted
    public static function rank(?string $level): int
    {
        $index = $level === null ? false : array_search($level, self::LEVELS, true);

        return $index === false ? -1 : $index;
    }

    // Whether moving from $previous to this tier makes `enforce` stricter
    public function raisesEnforceFrom(self $previous): bool
    {
        return self::rank($this->enforce) > self::rank($previous->enforce);
    }

    public function isActive(): bool
    {
        return $this->enforce !== null || $this->warn !== null || $this->audit !== null;
    }

    /**
     * The pod-security.kubernetes.io/* labels for this tier. The -version
     * label is only emitted when pinned, since a missing one means latest.
     *
     * @return array<string, string>
     */
    public function podSecurityLabels(): array
    {
        $labels = [];

        foreach (self::MODES as $mode) {
            $level = $this->$mode;
            if ($level === null) {
                continue;
            }

            $labels[self::LABEL_PREFIX.$mode] = $level;

            $version = $this->{$mode.'Version'};
            if ($version !== 'latest') {
                $labels[self::LABEL_PREFIX.$mode.'-version'] = $version;
            }
        }

        return $labels;
    }
}
