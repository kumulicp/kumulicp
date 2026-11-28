<?php

namespace App\Support\Volumes;

use App\AppInstance;
use App\Support\Facades\Application;
use App\Support\SettingsSnapshot;
use InvalidArgumentException;
use LogicException;

/**
 * The volumes of an app instance: the ones its profile declares (AppProfile::$volumes), each with
 * what the instance, its plan and the profile say about it (see Volume). Nothing else spells where
 * they are kept in the settings.
 *
 *     $volumes = Volumes::for($app_instance);
 *     $redis_master = $volumes->get('redis-master');
 *     $redis_master->size();
 *
 * The profile that says which volumes there are is the one of the chart the instance runs
 * (Application::profileFor()), so an app with an added catalog chart has that chart's.
 */
class Volumes
{
    /** What an instance can keep for a volume, in its settings under `volumes.<name>` */
    public const FIELDS = ['enabled', 'size', 'storage_class', 'access_mode', 'claim'];

    // What a profile can say about a volume; anything else is a typing mistake
    private const DEFINITION_KEYS = ['label', 'size', 'claim', 'optional', 'enabled', 'storage_class', 'access_mode'];

    // Names are keys in the settings, so no dots
    private const NAME = '/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/';

    /** @var array<string, Volume> */
    private array $volumes = [];

    /**
     * @param  array<string, array<string, mixed>>  $definitions  by name, as AppProfile::$volumes
     * @param  int|null  $storage  what the release the chart belongs to was given, in Gi, when it is not the instance's own (a shared app's)
     */
    public function __construct(private AppInstance $app_instance, array $definitions, private ?int $storage = null)
    {
        foreach ($definitions as $name => $definition) {
            $this->check((string) $name, $definition);

            $this->volumes[$name] = new Volume($this, $app_instance, $name, $definition);
        }
    }

    /**
     * @param  int|null  $storage  see __construct()
     */
    public static function for(AppInstance $app_instance, ?int $storage = null): self
    {
        return new self($app_instance, Application::profileFor($app_instance)->volumes(), $storage);
    }

    /**
     * @return array<string, Volume>
     */
    public function all(): array
    {
        return $this->volumes;
    }

    public function has(string $name): bool
    {
        return isset($this->volumes[$name]);
    }

    public function get(string $name): Volume
    {
        return $this->volumes[$name] ?? throw new InvalidArgumentException("The app has no volume '{$name}'");
    }

    /**
     * What the subscription gives the app to store, in Gi: 0 when it is not worked out yet.
     */
    public function quota(): int
    {
        return (int) Application::instance($this->app_instance)->storage()->totalAppStorage();
    }

    /**
     * What the release the chart belongs to was given, when that is not the instance's own.
     */
    public function releaseStorage(): ?int
    {
        return $this->storage !== null && $this->storage > 0 ? $this->storage : null;
    }

    /**
     * Writes what is given into the instance's settings for the volume, and gives back what was there
     * (see SettingsSnapshot), for restore(). A field that is not given is left alone, and one given as
     * null is forgotten, so what the plan or the profile say applies again.
     *
     * @param  array<string, mixed>  $values  by field (see FIELDS)
     * @return array<string, array{value: mixed}|null>
     */
    public function assign(string $name, array $values): array
    {
        $volume = $this->get($name);

        if ($unknown = array_diff(array_keys($values), self::FIELDS)) {
            throw new InvalidArgumentException('A volume has no '.implode(', ', $unknown));
        }

        if (filled($values['claim'] ?? null) && ! $volume->takesClaim()) {
            throw new LogicException("The volume '{$name}' can not be given a claim");
        }

        $writes = [];

        foreach ($values as $field => $value) {
            $writes["volumes.{$name}.{$field}"] = $this->normalized($field, $value);
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

    private function normalized(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field) {
            'enabled' => (bool) $value,
            'size' => (int) $value,
            'claim' => filled($value) ? (string) $value : null,
            default => (string) $value,
        };
    }

    /**
     * @param  mixed  $definition
     */
    private function check(string $name, $definition): void
    {
        if (! preg_match(self::NAME, $name)) {
            throw new InvalidArgumentException("A volume's name must be lowercase letters, digits and dashes: '{$name}'");
        }

        if (! is_array($definition)) {
            throw new InvalidArgumentException("The volume '{$name}' is not described by an array");
        }

        if ($unknown = array_diff(array_keys($definition), self::DEFINITION_KEYS)) {
            throw new InvalidArgumentException("The volume '{$name}' has no ".implode(', ', $unknown));
        }

        $size = $definition['size'] ?? null;

        if ($size !== 'quota' && ! (is_int($size) && $size >= 1)) {
            throw new InvalidArgumentException("The size of the volume '{$name}' is 'quota' or a number of Gi");
        }
    }
}
