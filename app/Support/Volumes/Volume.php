<?php

namespace App\Support\Volumes;

use App\AppInstance;
use Illuminate\Support\Arr;

/**
 * One volume of an app instance, as it is now. What it says is read when it is asked, so it is
 * never out of date after Volumes::assign().
 *
 * A field is the instance's own, else the plan's, else the profile's, the way a configuration is
 * (AppInstanceService::configuration()). A claim is the instance's alone: it is a fact about one
 * instance's volume, never a default.
 */
final class Volume
{
    /**
     * @param  array<string, mixed>  $definition  what the profile says (AppProfile::$volumes)
     */
    public function __construct(
        private Volumes $volumes,
        private AppInstance $app_instance,
        public readonly string $name,
        private array $definition,
    ) {}

    /** The key of the translation of its name */
    public function label(): string
    {
        return $this->definition['label'] ?? "admin.volumes.{$this->name}";
    }

    /** The app can do without it, so it is off until it is turned on */
    public function optional(): bool
    {
        return (bool) ($this->definition['optional'] ?? false);
    }

    /** It follows what the subscription gives the app, rather than being a size of its own */
    public function followsQuota(): bool
    {
        return $this->definition['size'] === 'quota';
    }

    /** It can be given a claim that already exists */
    public function takesClaim(): bool
    {
        return (bool) ($this->definition['claim'] ?? false);
    }

    public function enabled(): bool
    {
        return (bool) ($this->own('enabled') ?? $this->plan('enabled') ?? $this->definition['enabled'] ?? ! $this->optional());
    }

    /**
     * The size, in Gi, the volume is made with.
     *
     * One with a size of its own is the instance's, else the plan's, else the profile's. One that follows the
     * quota is the quota, which only ever grows: a claim can't be made smaller, so the largest size it has been
     * given is kept. A chart given the storage of its release (a shared app's) is that, and keeps nothing.
     */
    public function size(): int
    {
        if (! $this->followsQuota()) {
            return max(1, (int) ($this->own('size') ?? $this->plan('size') ?? $this->definition['size']));
        }

        if ($release_storage = $this->volumes->releaseStorage()) {
            return $release_storage;
        }

        $given = (int) $this->own('size');
        $quota = $this->volumes->quota();

        // Not a 0: the next release would take it for the size
        if ($quota > $given) {
            $this->volumes->assign($this->name, ['size' => $quota]);
            $given = $quota;
        }

        return max(1, $given);
    }

    public function storageClass(): string
    {
        return (string) ($this->own('storage_class') ?? $this->plan('storage_class') ?? $this->definition['storage_class'] ?? '');
    }

    public function accessMode(): ?string
    {
        $mode = $this->own('access_mode') ?? $this->plan('access_mode') ?? $this->definition['access_mode'] ?? null;

        return $mode === null ? null : (string) $mode;
    }

    /**
     * The claim that exists, that was chosen for the volume: only the instance's, and only for a volume
     * that takes one.
     */
    public function claim(): ?string
    {
        if (! $this->takesClaim()) {
            return null;
        }

        $claim = $this->own('claim');

        return filled($claim) ? (string) $claim : null;
    }

    // A null is not set: what the plan or the profile say applies
    private function own(string $field): mixed
    {
        return Arr::get($this->app_instance->settings ?? [], "volumes.{$this->name}.{$field}");
    }

    private function plan(string $field): mixed
    {
        return $this->app_instance->plan?->setting("volumes.{$this->name}.{$field}");
    }
}
