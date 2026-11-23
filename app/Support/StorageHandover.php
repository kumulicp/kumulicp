<?php

namespace App\Support;

use App\AppInstance;
use App\Support\Facades\Application;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Gives an app's chart a volume that already exists, under a name chosen on purpose, instead of
 * the one it would make itself (docs/server-transitions-plan.md, "Persistent volume handover").
 * A volume restored from a backup on another cluster comes back under its own claim, and a chart
 * that made a claim of the same name would collide with it.
 *
 * Each app profile says where its chart reads the claim, the access mode, the storage class and
 * the size from (AppProfile::storageHandover()); this writes them there, and gives back what was
 * there before so that it can be put back.
 */
class StorageHandover
{
    public const ACCESS_MODES = ['ReadWriteOnce', 'ReadWriteMany', 'ReadWriteOncePod', 'ReadOnlyMany'];

    // Names of claims and storage classes are DNS subdomain names
    private const NAME = '/^[a-z0-9]([-a-z0-9.]*[a-z0-9])?$/';

    /**
     * @return array<string, array<string, mixed>>|null
     */
    public static function map(AppInstance $app_instance): ?array
    {
        return Application::profile($app_instance->application)->storageHandover($app_instance);
    }

    public static function supports(AppInstance $app_instance): bool
    {
        return self::map($app_instance) !== null;
    }

    /**
     * What the app's chart can be given, for a form to show: the fields of the handover it has
     * somewhere to put.
     *
     * @return array<int, string>
     */
    public static function fields(AppInstance $app_instance): array
    {
        return array_keys(self::map($app_instance) ?? []);
    }

    /**
     * The rules for the form, for the fields under $prefix when it is part of a bigger one ('storage.').
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = ''): array
    {
        $rules = [
            // Not a default: moving to an empty volume has to be what was meant
            'volume' => ['required', Rule::in(['existing', 'new'])],
            'claim' => ['required_if:volume,existing', 'nullable', 'string', 'max:253', 'regex:'.self::NAME],
            'access_mode' => ['nullable', Rule::in(self::ACCESS_MODES)],
            'storage_class' => ['nullable', 'string', 'max:253', 'regex:'.self::NAME],
            'size' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];

        $prefixed = [];

        foreach ($rules as $field => $field_rules) {
            // A rule that names the other field names it where it now is
            $prefixed[$prefix.$field] = array_map(
                fn ($rule) => is_string($rule) ? str_replace('required_if:volume,', "required_if:{$prefix}volume,", $rule) : $rule,
                $field_rules,
            );
        }

        return $prefixed;
    }

    /**
     * The rules for this app: a chart that can't be given a volume that exists can only start with a new one.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(AppInstance $app_instance, string $prefix = ''): array
    {
        $rules = self::rules($prefix);

        if (! self::supports($app_instance)) {
            $rules["{$prefix}volume"] = ['required', Rule::in(['new'])];
        }

        return $rules;
    }

    /**
     * Writes the handover into the app's settings.
     *
     * With an existing volume the claim is its name; with a new one the claim is cleared, since
     * the name of the old volume's claim may be kept in the settings and a chart on another
     * cluster would otherwise look for it there. The access mode, storage class and size are
     * only written when given, and only where the chart reads them.
     *
     * Returns what each setting held before ('value' => ..., or null when it was not there), for
     * restore().
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{value: mixed}|null>
     */
    public static function apply(AppInstance $app_instance, array $values): array
    {
        $map = self::map($app_instance) ?? throw new \LogicException("{$app_instance->name} has no way to be given a volume");

        $writes = [];
        $existing = ($values['volume'] ?? null) === 'existing';

        $writes[$map['claim']['path']] = $existing ? (string) $values['claim'] : '';
        foreach ($map['claim'][$existing ? 'with' : 'without'] ?? [] as $path => $value) {
            $writes[$path] = $value;
        }

        foreach (['access_mode', 'storage_class', 'size'] as $field) {
            if (isset($map[$field]) && filled($values[$field] ?? null)) {
                $value = $field === 'size' ? (int) $values[$field] : $values[$field];
                $writes[$map[$field]['path']] = ($map[$field]['as'] ?? null) === 'list' ? [$value] : $value;
            }
        }

        $settings = $app_instance->settings ?? [];
        $before = [];

        foreach ($writes as $path => $value) {
            $before[$path] = Arr::has($settings, $path) ? ['value' => Arr::get($settings, $path)] : null;
            Arr::set($settings, $path, $value);
        }

        $app_instance->settings = $settings;
        $app_instance->save();

        return $before;
    }

    /**
     * Puts back what apply() gave.
     *
     * @param  array<string, array{value: mixed}|null>  $before
     */
    public static function restore(AppInstance $app_instance, array $before): void
    {
        $settings = $app_instance->settings ?? [];

        foreach ($before as $path => $was) {
            $was === null ? Arr::forget($settings, $path) : Arr::set($settings, $path, $was['value']);
        }

        $app_instance->settings = $settings;
        $app_instance->save();
    }
}
