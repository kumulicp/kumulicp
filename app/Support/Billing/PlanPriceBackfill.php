<?php

namespace App\Support\Billing;

/**
 * Pure helpers that move plan prices between the legacy settings JSON and
 * plan_prices rows. Used by the plan_prices migration (up and down), kept free
 * of the framework so the transformation can be tested on its own.
 *
 * Legacy settings hold prices in two shapes:
 *   {component}.prices.{currency}.{amount,price_id}   per-currency (current)
 *   {component}.{price,price_id}                      flat (pre-currency)
 * and the same two shapes under features.{name}.
 */
final class PlanPriceBackfill
{
    public const COMPONENTS = ['base', 'standard', 'basic', 'storage', 'application', 'email'];

    /** Prefix for an optional app feature's price component, e.g. "feature.deck". */
    public const FEATURE_PREFIX = 'feature.';

    public static function featureComponent(string $name): string
    {
        return self::FEATURE_PREFIX.$name;
    }

    /**
     * Pull every price out of a plan's settings.
     *
     * Per-currency entries are copied as they are. A flat price_id is copied
     * into the default currency only when that currency has no entry of its
     * own. The flat keys stay in the returned settings (display totals and
     * old fixtures still read them); only the per-currency "prices" subtrees
     * are removed, so the table is the single home for those.
     *
     * @param  array<string, mixed>  $settings
     * @return array{rows: list<array{component: string, currency: string, amount: float|int|string|null, external_id: string|null}>, settings: array<string, mixed>}
     */
    public static function extract(array $settings, string $defaultCurrency): array
    {
        $rows = [];

        foreach (self::COMPONENTS as $component) {
            if (! isset($settings[$component]) || ! is_array($settings[$component])) {
                continue;
            }

            $rows = array_merge($rows, self::rowsFor($component, $settings[$component], $defaultCurrency));
            unset($settings[$component]['prices']);
        }

        if (isset($settings['features']) && is_array($settings['features'])) {
            foreach ($settings['features'] as $name => $feature) {
                if (! is_array($feature)) {
                    continue;
                }

                $rows = array_merge($rows, self::rowsFor(self::featureComponent((string) $name), $feature, $defaultCurrency));
                unset($settings['features'][$name]['prices']);
            }
        }

        return ['rows' => $rows, 'settings' => $settings];
    }

    /**
     * Put rows back into settings as {component}.prices.{currency}.
     *
     * @param  array<string, mixed>  $settings
     * @param  iterable<array{component: string, currency: string, amount: mixed, external_id: string|null}>  $rows
     * @return array<string, mixed>
     */
    public static function restore(array $settings, iterable $rows): array
    {
        foreach ($rows as $row) {
            $entry = ['amount' => $row['amount'], 'price_id' => $row['external_id']];

            if (str_starts_with($row['component'], self::FEATURE_PREFIX)) {
                $name = substr($row['component'], strlen(self::FEATURE_PREFIX));
                $settings['features'][$name]['prices'][$row['currency']] = $entry;
            } else {
                $settings[$row['component']]['prices'][$row['currency']] = $entry;
            }
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $source  one component's (or feature's) settings
     * @return list<array{component: string, currency: string, amount: mixed, external_id: string|null}>
     */
    private static function rowsFor(string $component, array $source, string $defaultCurrency): array
    {
        $rows = [];

        $perCurrency = $source['prices'] ?? [];
        if (is_array($perCurrency)) {
            foreach ($perCurrency as $currency => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $amount = self::amountOrNull($entry['amount'] ?? null);
                $externalId = self::blankToNull($entry['price_id'] ?? null);

                if ($amount === null && $externalId === null) {
                    continue;
                }

                $rows[(string) $currency] = [
                    'component' => $component,
                    'currency' => (string) $currency,
                    'amount' => $amount,
                    'external_id' => $externalId,
                ];
            }
        }

        $flatId = self::blankToNull($source['price_id'] ?? null);
        if ($flatId !== null && ! isset($rows[$defaultCurrency])) {
            $rows[$defaultCurrency] = [
                'component' => $component,
                'currency' => $defaultCurrency,
                'amount' => self::amountOrNull($source['price'] ?? null),
                'external_id' => $flatId,
            ];
        }

        return array_values($rows);
    }

    private static function blankToNull(mixed $value): mixed
    {
        return $value === '' || $value === null ? null : $value;
    }

    // The amount column is numeric; anything else in old JSON becomes "no amount"
    // rather than failing the migration on a strict database.
    private static function amountOrNull(mixed $value): int|float|string|null
    {
        return is_numeric($value) ? $value : null;
    }
}
