<?php

namespace App\Support\Volumes;

use App\AppInstance;
use App\Support\Facades\Application;
use App\Support\SettingsSnapshot;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use LogicException;

/**
 * Every read and write of an app instance's volumes (docs/volumes-and-settings-plan.md, section 1).
 * No other code spells the settings paths.
 *
 * What an instance has chosen is kept at `workloads.<workload>.volumes.<volume>.<field>`, the same
 * whichever chart it runs. A field that is not there yet is read from where the chart used to keep
 * it (LegacyVolumeKeys), then from the plan and the profile (a configuration, as for any other
 * setting), so an instance behaves the same before and after its settings are migrated. Everything
 * is written to the new place only.
 *
 * Which volumes an instance has is what its chart declares. Until a chart does (slice 2), the one
 * every chart has: the primary volume.
 */
class Volumes
{
    /** What an instance keeps for a volume, under its key */
    public const FIELDS = ['enabled', 'size', 'storage_class', 'access_mode', 'claim', 'discovered'];

    /** @var array<string, VolumeSpec> */
    private array $specs = [];

    /**
     * @param  array<int, VolumeSpec>  $specs
     * @param  string|null  $layout  where the old places are, when the caller knows (see LegacyVolumeKeys); else from the chart the instance runs
     */
    public function __construct(private AppInstance $app_instance, array $specs, private ?string $layout = null)
    {
        foreach ($specs as $spec) {
            if (isset($this->specs[$spec->id()])) {
                throw new InvalidArgumentException("The volume {$spec->id()} is declared twice");
            }

            $this->specs[$spec->id()] = $spec;
        }

        if (count(array_filter($this->specs, fn (VolumeSpec $spec) => $spec->primary)) > 1) {
            throw new InvalidArgumentException('An app has one primary volume');
        }
    }

    /**
     * @param  array<int, VolumeSpec>|null  $specs  the volumes the chart declares; the primary one alone when not given
     */
    public static function for(AppInstance $app_instance, ?array $specs = null, ?string $layout = null): self
    {
        return new self($app_instance, $specs ?? [VolumeSpec::primary()], $layout);
    }

    /**
     * @return array<string, VolumeSpec> by id
     */
    public function specs(): array
    {
        return $this->specs;
    }

    /**
     * @return array<string, Volume> by id
     */
    public function all(): array
    {
        return array_map(fn (VolumeSpec $spec) => $this->resolve($spec), $this->specs);
    }

    public function primary(): Volume
    {
        foreach ($this->specs as $spec) {
            if ($spec->primary) {
                return $this->resolve($spec);
            }
        }

        throw new LogicException('The app has no primary volume');
    }

    public function get(string $workload, string $name): Volume
    {
        return $this->resolve($this->spec($workload, $name));
    }

    /**
     * The claim a job mounts: the one that was chosen, else the one that was found. Not the name
     * the chart would make for itself; that is the chart's to say (slice 2).
     */
    public function claimName(string $workload, string $name): ?string
    {
        return $this->get($workload, $name)->claimName();
    }

    /**
     * The size, in Gi, the volume is made with.
     *
     * A volume that follows the quota is given the quota when that is more than it has been
     * given, and keeps it: the size only ever grows, since a claim can't be made smaller. A
     * volume with a size of its own is that size.
     *
     * @param  int  $quota  what the subscription gives the app, in Gi; 0 when it is not worked out yet
     */
    public function size(string $workload, string $name, int $quota = 0): int
    {
        $spec = $this->spec($workload, $name);
        $given = $this->resolve($spec)->size;

        if ($spec->size_policy === 'fixed') {
            return $given ?? $spec->default_size;
        }

        $given ??= 0;

        // Not a 0: the next release would take it for the size
        if ($quota > 0 && $given < $quota) {
            $this->assign($workload, $name, ['size' => $quota]);
            $given = $quota;
        }

        return $given > 0 ? $given : 1;
    }

    /**
     * Writes what is given into the volume's place in the instance's settings, and gives back what
     * was there before (see SettingsSnapshot), for restore(). A field that is not given is left
     * alone. A null, or a blank claim, is a decision that there is none, and wins over what the
     * chart used to keep.
     *
     * @param  array<string, mixed>  $values  by field (see FIELDS)
     * @return array<string, array{value: mixed}|null>
     */
    public function assign(string $workload, string $name, array $values): array
    {
        $spec = $this->spec($workload, $name);

        if ($unknown = array_diff(array_keys($values), self::FIELDS)) {
            throw new InvalidArgumentException('A volume has no '.implode(', ', $unknown));
        }

        if (filled($values['claim'] ?? null) && $spec->claim_mode !== 'existing_claim') {
            throw new LogicException("The volume {$spec->id()} can not be given a claim ({$spec->claim_mode})");
        }

        $writes = [];

        foreach ($values as $field => $value) {
            $writes[$this->path($spec, $field)] = $this->normalized($field, $value);
        }

        return SettingsSnapshot::apply($this->app_instance, $writes);
    }

    /**
     * Puts back what assign() gave.
     *
     * @param  array<string, array{value: mixed}|null>  $before
     */
    public function restore(array $before): void
    {
        SettingsSnapshot::restore($this->app_instance, $before);
    }

    private function resolve(VolumeSpec $spec): Volume
    {
        $legacy = LegacyVolumeKeys::read($this->app_instance, $spec, $this->layout);

        $size = $this->read($spec, 'size', $legacy, fn () => $spec->size_policy === 'fixed'
            ? $this->configured($spec, 'size')
            : null);

        // A chart can keep several, as a list (WordPress's); the one it keeps is the first
        $modes = $this->read($spec, 'access_mode', $legacy, fn () => $this->configuredAccessModes($spec));

        return new Volume(
            spec: $spec,
            enabled: $this->read($spec, 'enabled', $legacy, fn () => $this->configured($spec, 'enabled') ?? ($spec->optional ? false : null)),
            // A size that is blank or 0 is none: the chart is left to say
            size: is_numeric($size) && (int) $size > 0 ? (int) $size : null,
            storage_class: $this->read($spec, 'storage_class', $legacy, fn () => $this->configured($spec, 'storageClass')),
            access_mode: is_array($modes) ? ($modes === [] ? null : (string) Arr::first($modes)) : ($modes === null ? null : (string) $modes),
            claim: $this->blank($this->read($spec, 'claim', $legacy, fn () => null)),
            discovered: $this->blank($this->read($spec, 'discovered', $legacy, fn () => null)),
            access_modes: array_values(array_map('strval', array_filter((array) $modes, fn ($mode) => $mode !== null && $mode !== ''))),
        );
    }

    /**
     * What the instance kept for the field; else what the chart used to keep; else the default.
     *
     * @param  array<string, mixed>  $legacy
     */
    private function read(VolumeSpec $spec, string $field, array $legacy, callable $default): mixed
    {
        $settings = $this->app_instance->settings ?? [];

        if (Arr::has($settings, $path = $this->path($spec, $field))) {
            return Arr::get($settings, $path);
        }

        return array_key_exists($field, $legacy) ? $legacy[$field] : $default();
    }

    /**
     * The plan's or the profile's value of a volume setting. The primary volume's are
     * `persistence-<field>`; another's have its workload in them (`persistence-redis-master-<field>`),
     * and its name when it is not the workload's only volume.
     */
    private function configured(VolumeSpec $spec, string $field): mixed
    {
        $prefix = $spec->primary ? 'persistence' : 'persistence-'.($spec->name === 'data' ? $spec->workload : "{$spec->workload}-{$spec->name}");

        return Application::instance($this->app_instance)->configuration("{$prefix}-{$field}");
    }

    // WordPress keeps the access modes as a list, under another name
    private function configuredAccessModes(VolumeSpec $spec): string|array|null
    {
        return $this->configured($spec, 'accessMode') ?? $this->configured($spec, 'accessModes');
    }

    private function spec(string $workload, string $name): VolumeSpec
    {
        return $this->specs["{$workload}/{$name}"] ?? throw new InvalidArgumentException("The app has no volume {$workload}/{$name}");
    }

    private function path(VolumeSpec $spec, string $field): string
    {
        return "workloads.{$spec->workload}.volumes.{$spec->name}.{$field}";
    }

    private function normalized(string $field, mixed $value): mixed
    {
        return match ($field) {
            'enabled' => $value === null ? null : (bool) $value,
            'size' => $value === null ? null : (int) $value,
            'claim', 'discovered' => $this->blank($value),
            default => $value === null ? null : (string) $value,
        };
    }

    // A blank name is no name
    private function blank(mixed $value): ?string
    {
        return filled($value) ? (string) $value : null;
    }
}
