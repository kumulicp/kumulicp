<?php

namespace App\Support;

use App\AppInstance;
use Illuminate\Support\Arr;

/**
 * Writes a set of values into an app instance's settings and gives back what each place held
 * before, so that a task that changes an instance can put it back when it is rolled back.
 *
 * The paths are in dot notation. What is given back for each is `['value' => ...]` for a place
 * that held something (a null included), and null for a place that was not there, so that
 * restore() can tell an empty setting from one that did not exist.
 */
class SettingsSnapshot
{
    /**
     * @param  array<string, mixed>  $writes  path => value
     * @return array<string, array{value: mixed}|null>
     */
    public static function apply(AppInstance $app_instance, array $writes): array
    {
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
