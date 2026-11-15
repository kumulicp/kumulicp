<?php

namespace App\Updates;

use App\AppInstance;
use App\AppPlan;
use App\Integrations\Applications\AppFeature;
use App\Support\Facades\Application;
use App\Updates\Steps\FeatureActionStep;
use Illuminate\Support\Arr;

/**
 * Which aspect the plan's and the instance's configurations and features
 * belong to, so that an aspect only fingerprints the part it brings up to date
 * (a change to the replica count is a new release, not a new settings job).
 *
 * A configuration belongs to the aspect named by its `aspect` key in the app
 * profile (or in the plan's additional configurations), and to `release` when
 * it names none: configurations end up in the chart's values. A feature
 * belongs to the aspect named by its `aspect` property, `settings` by default,
 * and to none of them when it brings an action of its own (FeatureAspect).
 *
 * The rest of a plan's settings are listed by the aspect they feed (PLAN_SETTINGS).
 * What isn't listed only matters to kumulicp (the most users a plan allows, how
 * long a trial lasts...) and is in no fingerprint.
 */
class AspectInputs
{
    public const DEFAULT_CONFIGURATION_ASPECT = 'release';

    public const DEFAULT_FEATURE_ASPECT = 'settings';

    /**
     * The plan settings (dotted paths) each aspect depends on. The ones that
     * set the storage an app gets are the release's: they decide the size of
     * its volume. Prices and what they are charged for are the subscription's.
     *
     * @var array<string, array<int, string>>
     */
    private const PLAN_SETTINGS = [
        'release' => [
            'additionalConfigs', 'secrets',
            'base.storage', 'standard.storage', 'basic.storage', 'storage.amount',
        ],
        'settings' => ['additionalConfigs'],
        'subscription' => [
            'base.prices', 'base.price', 'base.price_id',
            'standard.prices', 'standard.price', 'standard.price_id',
            'basic.prices', 'basic.price', 'basic.price_id', 'basic.amount',
            'storage.prices', 'storage.price', 'storage.price_id', 'storage.amount',
        ],
    ];

    /** What of a plan's feature is about the subscription: whether it is on, and what it costs */
    private const FEATURE_PRICING = ['status', 'price', 'price_id', 'prices', 'payment_type'];

    public static function configurationAspect(AppInstance $app_instance, string $configuration): string
    {
        $config = Application::profile($app_instance->application)->configuration($configuration);

        return Arr::get($config, 'aspect')
            ?? Arr::get((array) $app_instance->plan?->additionalConfigs(), "$configuration.aspect")
            ?? self::DEFAULT_CONFIGURATION_ASPECT;
    }

    /** The aspect a feature belongs to, null when only its own aspect (FeatureAspect) is about it */
    public static function featureAspect(AppInstance $app_instance, string $feature): ?string
    {
        $found = Application::profile($app_instance->application)->features()->get($feature);

        if ($aspect = $found->aspect ?? null) {
            return $aspect;
        }

        return $found instanceof AppFeature && FeatureActionStep::for($found) ? null : self::DEFAULT_FEATURE_ASPECT;
    }

    /**
     * What a plan's settings give an aspect: the settings listed for it
     * (PLAN_SETTINGS), and the configurations that belong to it.
     */
    public static function planSettings(AppInstance $app_instance, string $aspect): array
    {
        $settings = (array) $app_instance->plan?->settings;
        $given = [];

        foreach (self::PLAN_SETTINGS[$aspect] ?? [] as $path) {
            if (Arr::has($settings, $path)) {
                Arr::set($given, $path, Arr::get($settings, $path));
            }
        }

        $given['configurations'] = self::configurations($app_instance, (array) Arr::get($settings, 'configurations'), $aspect);

        return $given;
    }

    /**
     * What the plan says the features that cost something cost, and whether
     * they are on: the subscription's. A feature without a price isn't billed.
     */
    public static function featurePricing(?AppPlan $plan): array
    {
        $pricing = [];

        foreach ((array) $plan?->setting('features') as $name => $feature) {
            $feature = is_array($feature) ? Arr::only($feature, self::FEATURE_PRICING) : [];

            if (array_filter(Arr::only($feature, ['price', 'price_id', 'prices']))) {
                $pricing[$name] = $feature;
            }
        }

        return $pricing;
    }

    /** The configurations (by name) that belong to the aspect */
    public static function configurations(AppInstance $app_instance, array $configurations, string $aspect): array
    {
        return array_filter(
            $configurations,
            fn ($value, $name) => self::configurationAspect($app_instance, (string) $name) === $aspect,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The features of the plan and of the instance that belong to the aspect
     *
     * @return array{plan: array, instance: array}
     */
    public static function features(AppInstance $app_instance, string $aspect): array
    {
        $names = array_unique(array_merge(
            array_keys((array) $app_instance->plan?->setting('features')),
            array_keys((array) $app_instance->setting('features')),
        ));

        return self::featureState($app_instance, array_values(array_filter(
            $names,
            fn ($name) => self::featureAspect($app_instance, (string) $name) === $aspect,
        )));
    }

    /**
     * What the plan and the instance say about the features, by name: whether
     * they are on and their settings. The prices and the order they are shown
     * in say nothing about the app.
     *
     * @param  array<int, string>  $names
     * @return array{plan: array, instance: array}
     */
    public static function featureState(AppInstance $app_instance, array $names): array
    {
        $state = ['plan' => [], 'instance' => []];

        foreach ($names as $name) {
            if ($plan_feature = self::planFeature($app_instance->plan, $name)) {
                $state['plan'][$name] = $plan_feature;
            }

            if (($instance_feature = $app_instance->setting("features.$name")) !== null) {
                $state['instance'][$name] = $instance_feature;
            }
        }

        return $state;
    }

    public static function planFeature(?AppPlan $plan, string $name): ?array
    {
        $feature = $plan?->featureValue($name);

        return is_array($feature) ? Arr::only($feature, ['status', 'settings']) : null;
    }
}
