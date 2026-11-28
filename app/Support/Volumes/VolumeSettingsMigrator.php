<?php

namespace App\Support\Volumes;

use Illuminate\Support\Arr;

/**
 * Moves what a plan's or an instance's settings keep about volumes in the places each chart used to keep
 * it into `volumes.<name>.*` (docs/volumes-and-settings-plan.md, "The migration"). It works on the settings
 * as they are stored and says what it would add, so it can be run again and shown before it is run
 * (see the `volumes:migrate` command). It never removes anything: the old keys stay until a later release
 * takes them out, so the code that read them can still be rolled back to.
 *
 * Which keys a chart used is decided by the chart class that deploys the app, as a layout:
 * 'nextcloud', 'wordpress', 'civicrm', 'catalog', or 'other' for any other, which only ever had `chart_storage`.
 * The generic chart's volumes are a list the plan defines and are migrated on their own.
 *
 * The old places are frozen here, on purpose, so that a later change to a profile can't change what an old
 * migration does.
 */
final class VolumeSettingsMigrator
{
    /** @var array<string, array<string, string>> the `configurations` name each field of the data volume was kept in, by layout */
    private const DATA = [
        'nextcloud' => ['enabled' => 'persistence-enabled', 'storage_class' => 'persistence-storageClass', 'access_mode' => 'persistence-accessMode'],
        'wordpress' => ['enabled' => 'persistence-enabled', 'storage_class' => 'persistence-storageClass', 'access_mode' => 'persistence-accessModes'],
        'civicrm' => ['enabled' => 'persistence-enabled', 'storage_class' => 'persistence-storageClass', 'access_mode' => 'persistence-accessModes'],
        // Its chart was only ever given the class
        'catalog' => ['storage_class' => 'persistence-storageClass'],
    ];

    /** @var array<string, array<string, array<string, string>>> the configuration the chart takes as it is, and where each volume is in it, by layout */
    private const BLOBS = [
        'nextcloud' => [
            'redis' => ['redis-master' => 'master.persistence', 'redis-replica' => 'replica.persistence'],
            'mariadb' => ['mariadb' => 'primary.persistence'],
        ],
        'wordpress' => [
            'mariadb' => ['mariadb' => 'primary.persistence'],
        ],
    ];

    private const BLOB_FIELDS = ['enabled' => 'enabled', 'size' => 'size', 'storage_class' => 'storageClass', 'access_mode' => 'accessMode'];

    private const BINARY = ['Ki' => 1024, 'Mi' => 1024 ** 2, 'Gi' => 1024 ** 3, 'Ti' => 1024 ** 4];

    private const DECIMAL = ['k' => 1000, 'K' => 1000, 'M' => 1000 ** 2, 'G' => 1000 ** 3, 'T' => 1000 ** 4];

    public function __construct(private string $layout) {}

    /**
     * What to add to a plan's settings.
     *
     * @param  array<string, mixed>  $settings  the plan's, as stored
     * @return array{volumes: array<string, array<string, mixed>>, notes: array<int, string>}
     */
    public function plan(array $settings): array
    {
        $volumes = [];
        $notes = [];

        $this->fromConfigurations($settings, $volumes, $notes);

        return ['volumes' => $volumes, 'notes' => $notes];
    }

    /**
     * What to add to an instance's settings. A value the plan already gives the volume is left out, so the
     * instance goes on following the plan and keeps only what is its own.
     *
     * @param  array<string, mixed>  $settings  the instance's, as stored
     * @param  array<string, array<string, mixed>>  $plan_volumes  what the plan's `volumes` will be once it is migrated
     * @return array{volumes: array<string, array<string, mixed>>, notes: array<int, string>}
     */
    public function instance(array $settings, array $plan_volumes = []): array
    {
        $volumes = [];
        $notes = [];

        $this->fromConfigurations($settings, $volumes, $notes);
        $this->fromInstance($settings, $volumes, $notes);

        foreach ($volumes as $name => $fields) {
            $volumes[$name] = array_filter($fields, fn ($value, $field) => ! (array_key_exists($field, $plan_volumes[$name] ?? []) && $plan_volumes[$name][$field] === $value), ARRAY_FILTER_USE_BOTH);
        }

        return ['volumes' => array_filter($volumes), 'notes' => $notes];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, array<string, mixed>>  $volumes
     * @param  array<int, string>  $notes
     */
    private function fromConfigurations(array $settings, array &$volumes, array &$notes): void
    {
        foreach (self::DATA[$this->layout] ?? [] as $field => $name) {
            if (! Arr::has($settings, "configurations.{$name}")) {
                continue;
            }

            $value = Arr::get($settings, "configurations.{$name}");

            $value = match ($field) {
                'enabled' => $this->boolean($value, "configurations.{$name}", $notes),
                'access_mode' => $this->mode($value, $notes),
                default => is_string($value) ? $value : null,
            };

            if ($value !== null) {
                $volumes['data'][$field] = $value;
            }
        }

        foreach (self::BLOBS[$this->layout] ?? [] as $configuration => $places) {
            foreach ($places as $name => $path) {
                $this->fromBlob($settings, $configuration, $path, $name, $volumes, $notes);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, array<string, mixed>>  $volumes
     * @param  array<int, string>  $notes
     */
    private function fromBlob(array $settings, string $configuration, string $path, string $name, array &$volumes, array &$notes): void
    {
        foreach (self::BLOB_FIELDS as $field => $key) {
            if (! Arr::has($settings, "configurations.{$configuration}.{$path}.{$key}")) {
                continue;
            }

            $value = Arr::get($settings, "configurations.{$configuration}.{$path}.{$key}");
            $where = "configurations.{$configuration}.{$path}.{$key}";

            $value = match ($field) {
                'enabled' => $this->boolean($value, $where, $notes),
                'size' => $this->quantity($value, $where, $notes),
                default => is_string($value) ? $value : null,
            };

            if ($value !== null) {
                $volumes[$name][$field] = $value;
            }
        }

        // Only the volume that holds the files takes a claim
        if (filled(Arr::get($settings, "configurations.{$configuration}.{$path}.existingClaim"))) {
            $notes[] = "configurations.{$configuration}.{$path}.existingClaim is not kept: only the data volume takes a claim";
        }
    }

    /**
     * What only an instance has: the claim and the size it has been given.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, array<string, mixed>>  $volumes
     * @param  array<int, string>  $notes
     */
    private function fromInstance(array $settings, array &$volumes, array &$notes): void
    {
        $claim = match ($this->layout) {
            'nextcloud' => Arr::get($settings, 'existing_claim'),
            'wordpress', 'catalog' => Arr::get($settings, 'override.pvc.name'),
            'civicrm' => Arr::get($settings, 'override.pvc.override') === true ? Arr::get($settings, 'override.pvc.name') : null,
            default => null,
        };

        if (is_string($claim) && filled($claim)) {
            $volumes['data']['claim'] = trim($claim);

            // Nothing says whether the name was chosen or is one a job found on the cluster
            if (in_array($this->layout, ['wordpress', 'catalog'], true)) {
                $notes[] = "override.pvc.name is kept as the claim ('{$claim}'); check that it is one that was chosen and not the chart's own";
            }
        }

        if ($this->layout === 'civicrm' && Arr::get($settings, 'override.pvc.override') !== true && filled(Arr::get($settings, 'override.pvc.name'))) {
            $notes[] = 'override.pvc.name is not kept: it was not told to be used, so it is a name a job found';
        }

        $size = Arr::get($settings, 'chart_storage');

        if (is_numeric($size) && (int) $size >= 1) {
            $volumes['data']['size'] = (int) $size;
        }
    }

    /**
     * @param  array<int, string>  $notes
     */
    private function boolean(mixed $value, string $where, array &$notes): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $notes[] = "{$where} is not true or false, so it is not kept";

        return null;
    }

    /**
     * One access mode, from a string or a list.
     *
     * @param  array<int, string>  $notes
     */
    private function mode(mixed $value, array &$notes): ?string
    {
        if (is_array($value)) {
            if (count($value) > 1) {
                $notes[] = 'more than one access mode ('.implode(', ', array_map('strval', $value)).'): only the first is kept';
            }

            $value = Arr::first($value);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A size such as `8Gi` as a whole number of Gi, rounded up when it is not.
     *
     * @param  array<int, string>  $notes
     */
    private function quantity(mixed $value, string $where, array &$notes): ?int
    {
        if (! is_string($value) || ! preg_match('/^(\d+(?:\.\d+)?)(Ki|Mi|Gi|Ti|k|K|M|G|T)?$/', $value, $match)) {
            $notes[] = "{$where} ('".(is_scalar($value) ? $value : gettype($value))."') is not a size, so it is not kept";

            return null;
        }

        $bytes = (float) $match[1] * (self::BINARY[$match[2] ?? ''] ?? self::DECIMAL[$match[2] ?? ''] ?? 1);
        $gibibytes = $bytes / self::BINARY['Gi'];

        if ($gibibytes <= 0) {
            return null;
        }

        if ($gibibytes !== floor($gibibytes)) {
            $notes[] = "{$where} ({$value}) is not a whole number of Gi: rounded up to ".(int) ceil($gibibytes).'Gi';
        }

        return max(1, (int) ceil($gibibytes));
    }
}
