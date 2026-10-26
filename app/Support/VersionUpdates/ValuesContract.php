<?php

namespace App\Support\VersionUpdates;

use App\AppInstance;
use App\AppVersion;
use App\Integrations\ServerManagers\Rancher\Charts\HelmChart;
use App\Support\Facades\Application;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Checks the Helm values Kumulicp generates for an app against a chart's
 * defaults and schema. Helm silently ignores unknown keys, so a chart that
 * renames or drops a value Kumulicp sets would otherwise break an app with
 * no error at install time.
 */
class ValuesContract
{
    private const MAX_ISSUES = 20;

    /**
     * The values Kumulicp would send for a real instance running this
     * version, or null when no instance exists to render them from. Values
     * builders may persist small bookkeeping settings, so rendering runs in
     * a transaction that is always rolled back. The result is only compared
     * in memory and never stored.
     */
    public function render(AppVersion $version): ?array
    {
        $app_instance = AppInstance::where('version_id', $version->id)
            ->notDeactivated()
            ->latest('id')
            ->first();

        if (! $app_instance) {
            return null;
        }

        DB::beginTransaction();

        try {
            $chart = Application::instance($app_instance)->charts()[0] ?? null;

            return $chart instanceof HelmChart ? $chart->valuesWithAdditionalConfigs() : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Keys Kumulicp sets that exist in the current chart's defaults but are
     * gone from the candidate's. With no rendered values, every default the
     * candidate dropped is returned instead, as a weaker signal.
     *
     * @return array<int, string>
     */
    public function removedKeys(?array $rendered, array $current_defaults, array $candidate_defaults): array
    {
        $paths = $rendered !== null ? $this->leafPaths($rendered) : $this->leafPaths($current_defaults);
        $removed = [];

        foreach ($paths as $segments) {
            $current_depth = $this->depth($current_defaults, $segments);
            $candidate_depth = $this->depth($candidate_defaults, $segments);

            if ($candidate_depth < $current_depth) {
                $removed[] = implode('.', array_slice($segments, 0, $candidate_depth + 1));
            }
        }

        return array_slice(array_values(array_unique($removed)), 0, self::MAX_ISSUES);
    }

    /**
     * Validates values against a chart's values.schema.json, covering the
     * JSON Schema keywords charts actually use: type, enum, required,
     * properties, additionalProperties and items. Null values are dropped
     * first, as Helm does before validating.
     *
     * @return array<int, string>
     */
    public function schemaErrors(array $values, array $schema): array
    {
        $errors = [];
        $this->validate($this->withoutNulls($values), $schema, '', $errors);

        return array_slice($errors, 0, self::MAX_ISSUES);
    }

    private function validate(mixed $value, array $schema, string $path, array &$errors): void
    {
        $label = $path === '' ? '(root)' : $path;

        if (isset($schema['type']) && ! $this->matchesType($value, (array) $schema['type'])) {
            $errors[] = __('admin.version_updates.schema_type', ['key' => $label, 'type' => implode('|', (array) $schema['type'])]);

            return;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = __('admin.version_updates.schema_enum', ['key' => $label]);
        }

        if (is_array($value) && ! array_is_list($value)) {
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists($required, $value)) {
                    $errors[] = __('admin.version_updates.schema_required', ['key' => ltrim($path.'.'.$required, '.')]);
                }
            }

            $properties = (array) ($schema['properties'] ?? []);
            foreach ($value as $key => $child) {
                $child_path = ltrim($path.'.'.$key, '.');

                if (isset($properties[$key]) && is_array($properties[$key])) {
                    $this->validate($child, $properties[$key], $child_path, $errors);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = __('admin.version_updates.schema_unknown_key', ['key' => $child_path]);
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $this->validate($child, $schema['additionalProperties'], $child_path, $errors);
                }
            }
        }

        if (is_array($value) && array_is_list($value) && is_array($schema['items'] ?? null)) {
            foreach ($value as $index => $item) {
                $this->validate($item, $schema['items'], $path.'['.$index.']', $errors);
            }
        }
    }

    private function matchesType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                // PHP can't tell an empty JSON object from an empty array
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                default => true,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function withoutNulls(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }

            $result[$key] = is_array($value) && ! array_is_list($value) ? $this->withoutNulls($value) : $value;
        }

        return $result;
    }

    /**
     * Paths to every leaf; lists and empty maps count as leaves.
     *
     * @return array<int, array<int, string>>
     */
    private function leafPaths(array $values, array $prefix = []): array
    {
        $paths = [];

        foreach ($values as $key => $value) {
            $segments = [...$prefix, (string) $key];

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $paths = array_merge($paths, $this->leafPaths($value, $segments));
            } else {
                $paths[] = $segments;
            }
        }

        return $paths;
    }

    // How many leading segments of the path exist as keys in the defaults.
    private function depth(array $defaults, array $segments): int
    {
        $depth = 0;
        $node = $defaults;

        foreach ($segments as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                break;
            }

            $node = $node[$segment];
            $depth++;
        }

        return $depth;
    }
}
