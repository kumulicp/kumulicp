<?php

namespace App\Support\Volumes;

use App\AppInstance;
use App\Integrations\Applications\CiviCRMStandalone\CiviCRMStandaloneChart;
use App\Integrations\Applications\HelmForge\HelmForgeChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\GenericChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\NextcloudChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\WordpressChart;
use App\Support\Facades\Application;
use Illuminate\Support\Arr;

/**
 * Where each chart kept its volume before they were all kept in one place
 * (docs/volumes-and-settings-plan.md, "Migration"). Volumes reads from here only for what the
 * instance has not yet been given under `workloads.*.volumes.*`, so an instance works the same
 * before and after its settings are migrated.
 *
 * This is the one place with that knowledge. It goes with the migration (slice 8), and a frozen
 * copy of it lives in the migration that moves the settings (slice 4).
 *
 * Which keys are read is decided by the chart class that deploys the instance, through its profile
 * (Application::profileFor()), not by its application: an app with an added catalog chart has
 * two layouts. A chart that is given the instance says its own (layoutOf()).
 */
final class LegacyVolumeKeys
{
    /**
     * What the instance's settings say, in the old places, about this volume. Only the fields a
     * layout has an opinion on are given; a field that is not in the answer is not known, and a
     * null in it is known to be empty.
     *
     * Only the primary volume ever had a place of its own, and the generic chart's volumes, one
     * for each item. The volumes of other workloads were parts of a configuration blob the chart took
     * as it was.
     *
     * @param  string|null  $layout  the layout to read, when the caller knows it; else the one of the chart the instance runs
     * @return array{claim?: string|null, discovered?: string|null, size?: int|null, storage_class?: string|null, access_mode?: string|null}
     */
    public static function read(AppInstance $app_instance, VolumeSpec $spec, ?string $layout = null): array
    {
        $layout ??= self::layout($app_instance);

        // The generic chart keeps every volume of the app as an item of its own; for the rest only
        // the primary one had a place
        if (! $spec->primary && $layout !== 'generic') {
            return [];
        }

        $settings = $app_instance->settings ?? [];
        $stored_size = self::number(Arr::get($settings, 'chart_storage'));

        return match ($layout) {
            'nextcloud' => [
                'claim' => self::text(Arr::get($settings, 'existing_claim')),
                'size' => $stored_size,
            ],
            'wordpress', 'catalog' => [
                // Nothing says whether a name here was chosen or found by a job, so it is a claim
                'claim' => self::text(Arr::get($settings, 'override.pvc.name')),
                'size' => $stored_size,
            ],
            'civicrm' => self::civicrm($settings, $stored_size),
            'generic' => self::generic($app_instance, $spec, $stored_size),
            default => [],
        };
    }

    /**
     * The layout of the chart the instance runs, or null for one that has none of the old places.
     */
    public static function layout(AppInstance $app_instance): ?string
    {
        // A profile can have several charts (a job and an app)
        foreach ((array) Application::profileFor($app_instance)->chart() as $chart) {
            if (is_string($chart) && $layout = self::layoutOf($chart)) {
                return $layout;
            }
        }

        return null;
    }

    /**
     * The layout of the instances a chart class deploys: where that chart read its volume from.
     *
     * @param  class-string|string  $chart
     */
    public static function layoutOf(string $chart): ?string
    {
        return match (true) {
            is_a($chart, NextcloudChart::class, true) => 'nextcloud',
            is_a($chart, WordpressChart::class, true) => 'wordpress',
            is_a($chart, CiviCRMStandaloneChart::class, true) => 'civicrm',
            is_a($chart, HelmForgeChart::class, true) => 'catalog',
            is_a($chart, GenericChart::class, true) => 'generic',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function civicrm(array $settings, ?int $stored_size): array
    {
        $name = self::text(Arr::get($settings, 'override.pvc.name'));

        // The chart only takes the name when told to; the rest is what a job found
        $chosen = Arr::get($settings, 'override.pvc.override') === true;

        return [
            'claim' => $chosen ? $name : null,
            'discovered' => $chosen ? null : $name,
            'size' => $stored_size,
        ];
    }

    /**
     * The generic chart keeps each volume as an item of an array configuration; the volume is
     * the item with its name. An item with no size of its own is sized as the app is.
     *
     * @return array<string, mixed>
     */
    private static function generic(AppInstance $app_instance, VolumeSpec $spec, ?int $stored_size): array
    {
        foreach (Application::instance($app_instance)->arrayConfiguration('persistence') as $item) {
            if (($item['name'] ?? null) !== $spec->name) {
                continue;
            }

            return [
                'claim' => self::text($item['existingClaim'] ?? null),
                'size' => self::number($item['size'] ?? null) ?? $stored_size,
                'storage_class' => isset($item['storageClass']) ? (string) $item['storageClass'] : null,
                'access_mode' => isset($item['accessMode']) ? (string) $item['accessMode'] : null,
            ];
        }

        return [];
    }

    // A blank name is no name
    private static function text(mixed $value): ?string
    {
        return filled($value) ? (string) $value : null;
    }

    private static function number(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
