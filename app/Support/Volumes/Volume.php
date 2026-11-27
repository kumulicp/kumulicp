<?php

namespace App\Support\Volumes;

/**
 * One volume of an app instance as it is now: what the chart declares for it (the spec) and what
 * the instance, its plan or the profile give it. Made by Volumes; it has no way to change itself.
 *
 * A null is not known: left to the chart's own default. That includes `enabled`: see on(). The storage class and the access mode are
 * as they are kept, so '' (the chart's default, said out loud) is not null.
 */
final class Volume
{
    public function __construct(
        public readonly VolumeSpec $spec,
        public readonly ?bool $enabled,
        public readonly ?int $size,
        public readonly ?string $storage_class,
        public readonly ?string $access_mode,
        /** A claim that exists, chosen by a person or by a move */
        public readonly ?string $claim,
        /** A claim the cluster reported, which is kept for jobs and never given to the chart */
        public readonly ?string $discovered,
        /** Every access mode, for a chart that takes a list; `access_mode` is the first of them */
        public readonly array $access_modes = [],
    ) {}

    /**
     * Whether the volume is on. Not what `enabled` says when it says nothing: a volume the app can do
     * without is off until it is turned on, and the one it can't is on.
     */
    public function on(): bool
    {
        return $this->enabled ?? ! $this->spec->optional;
    }

    public function id(): string
    {
        return $this->spec->id();
    }

    /**
     * The claim a job mounts: the one that was chosen, else the one that was found.
     */
    public function claimName(): ?string
    {
        return $this->claim ?? $this->discovered;
    }
}
