<?php

namespace App\Support\Billing;

use App\PlanPrice;
use App\Support\Facades\Settings as SettingsFacade;
use App\Updates\Detection\DefinitionChanges;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Price lookups and edits for the models that own plan_prices rows (Plan and
 * AppPlan). Reads fall back from the requested currency to the default
 * currency, then to the flat price/price_id keys older plans still carry in
 * their settings.
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PlanPrice> $prices
 */
trait HasPlanPrices
{
    /**
     * @return MorphMany<PlanPrice, $this>
     */
    public function prices(): MorphMany
    {
        return $this->morphMany(PlanPrice::class, 'priceable');
    }

    /**
     * Returns ['amount' => ..., 'price_id' => ...] for the given component and currency.
     * price_id is the active billing driver's id for the price (or $driver's).
     */
    public function priceFor(string $component, string $currency, ?string $driver = null): array
    {
        foreach ($this->priceCurrencies($currency) as $candidate) {
            $row = $this->priceRow($component, $candidate);
            $priceId = $row?->externalId($driver);

            if ($priceId) {
                return ['amount' => $row->amount, 'price_id' => $priceId];
            }
        }

        // Legacy flat format (pre-currency migration)
        return [
            'amount' => $this->setting("{$component}.price"),
            'price_id' => $this->setting("{$component}.price_id"),
        ];
    }

    /**
     * The amount of a component in the given currency (the default currency when
     * none is given), for showing what a plan costs.
     *
     * Unlike priceFor() this does not need the price to have an id for the active
     * billing driver: a plan that is priced but not (yet) set up in the billing
     * engine still shows its price.
     */
    public function amountFor(string $component, ?string $currency = null): mixed
    {
        foreach ($this->priceCurrencies($currency ?? SettingsFacade::get('default_currency', 'USD')) as $candidate) {
            $amount = $this->priceRow($component, $candidate)?->amount;

            if ($amount !== null) {
                return $amount;
            }
        }

        // Legacy flat format
        return $this->setting("{$component}.price");
    }

    /**
     * Returns the billing driver's price id for an optional feature in the given currency.
     */
    public function featurePriceId(string $featureName, string $currency, ?string $driver = null): ?string
    {
        $component = PlanPriceBackfill::featureComponent($featureName);

        foreach ($this->priceCurrencies($currency) as $candidate) {
            $priceId = $this->priceRow($component, $candidate)?->externalId($driver);

            if ($priceId) {
                return $priceId;
            }
        }

        // Legacy flat format
        return $this->setting("features.{$featureName}.price_id");
    }

    /**
     * Returns the amount for an optional feature in the given currency.
     */
    public function featurePriceAmount(string $featureName, string $currency): mixed
    {
        $component = PlanPriceBackfill::featureComponent($featureName);

        foreach ($this->priceCurrencies($currency) as $candidate) {
            $amount = $this->priceRow($component, $candidate)?->amount;

            if ($amount !== null) {
                return $amount;
            }
        }

        // Legacy flat format
        return $this->setting("features.{$featureName}.price");
    }

    /**
     * Create, update or remove one price. A price with neither an amount nor an
     * id for any driver is deleted. Only $driver's id is touched, so ids stored
     * for other billing engines are kept.
     *
     * Does not note the change for the app instances that depend on the plan;
     * syncPrices() and syncFeaturePrices() do, once for all the prices they change.
     *
     * @return bool whether the price is different from what was stored
     */
    public function setPrice(string $component, string $currency, mixed $amount, ?string $externalId, ?string $driver = null): bool
    {
        $driver ??= PlanPrice::activeDriver();
        $amount = ($amount === '' || $amount === null) ? null : (float) $amount;
        $externalId = ($externalId === '' || $externalId === null) ? null : $externalId;

        $row = $this->prices()->where('component', $component)->where('currency', $currency)->first();

        $externalIds = $row->external_ids ?? [];
        if ($externalId === null) {
            unset($externalIds[$driver]);
        } else {
            $externalIds[$driver] = $externalId;
        }

        $changed = ($row?->amount !== $amount) || (($row->external_ids ?? []) != $externalIds);

        if (! $changed) {
            return false;
        }

        if ($amount === null && $externalIds === []) {
            $row?->delete();
        } else {
            $row ??= new PlanPrice(['component' => $component, 'currency' => $currency]);
            $row->amount = $amount;
            $row->external_ids = $externalIds === [] ? null : $externalIds;
            $this->prices()->save($row);
        }

        $this->unsetRelation('prices');

        return true;
    }

    /**
     * Apply the admin form's price input: [component => [currency => ['amount' => , 'price_id' => ]]].
     * Only the components and currencies present in the input are changed.
     *
     * @param  array<string, array<string, array{amount?: mixed, price_id?: mixed}>>  $prices
     */
    public function syncPrices(array $prices, ?string $driver = null): void
    {
        $changed = false;

        foreach ($prices as $component => $currencies) {
            foreach ($currencies as $currency => $entry) {
                $changed = $this->setPrice($component, $currency, $entry['amount'] ?? null, $entry['price_id'] ?? null, $driver) || $changed;
            }
        }

        if ($changed) {
            $this->recordPriceChange();
        }
    }

    /**
     * Save the prices of optional features as the features form submits them.
     *
     * A feature with per-currency "prices" ([currency => [amount, price_id]]) has
     * those saved for each enabled currency it lists; the others are left alone.
     * One with only the flat price/price_id has them saved as its default-currency
     * price.
     *
     * @param  array<string, mixed>  $features  feature name => submitted feature (or 'on'/'off')
     * @return bool whether any price is different from what was stored
     */
    public function syncFeaturePrices(array $features): bool
    {
        $default = SettingsFacade::get('default_currency', 'USD');
        $changed = false;

        foreach ($features as $name => $feature) {
            $feature = is_array($feature) ? $feature : [];
            $component = PlanPriceBackfill::featureComponent((string) $name);
            $perCurrency = $feature['prices'] ?? null;

            if (is_array($perCurrency) && $perCurrency !== []) {
                foreach ($this->enabledCurrencies() as $currency) {
                    if (is_array($perCurrency[$currency] ?? null)) {
                        $changed = $this->setPrice($component, $currency, $perCurrency[$currency]['amount'] ?? null, $perCurrency[$currency]['price_id'] ?? null) || $changed;
                    }
                }
            } else {
                $changed = $this->setPrice($component, $default, $feature['price'] ?? null, $feature['price_id'] ?? null) || $changed;
            }
        }

        if ($changed) {
            $this->recordPriceChange();
        }

        return $changed;
    }

    /**
     * Copy every price (amounts and ids for all drivers) onto another plan, for
     * cloning. Replicating a plan copies its attributes but not its price rows.
     */
    public function copyPricesTo(self $target): void
    {
        foreach ($this->prices as $price) {
            $target->prices()->create([
                'component' => $price->component,
                'currency' => $price->currency,
                'amount' => $price->amount,
                'external_ids' => $price->external_ids,
            ]);
        }

        $target->unsetRelation('prices');
    }

    /**
     * Remove every price of a component, in every currency.
     */
    public function clearPrices(string $component): void
    {
        if ($this->prices()->where('component', $component)->delete() > 0) {
            $this->recordPriceChange();
        }

        $this->unsetRelation('prices');
    }

    /**
     * The prices in the shape the admin forms read from settings:
     * [component => [currency => ['amount' => , 'price_id' => ]]].
     * price_id is the active driver's id.
     *
     * @return array<string, array<string, array{amount: float|null, price_id: string|null}>>
     */
    public function pricesForForm(?string $driver = null): array
    {
        $form = [];

        foreach ($this->prices as $price) {
            $form[$price->component][$price->currency] = [
                'amount' => $price->amount,
                'price_id' => $price->externalId($driver),
            ];
        }

        return $form;
    }

    /**
     * Lay the table's prices over a settings array as {component}.prices.{currency},
     * the shape the admin forms read. Prices are no longer stored in settings, so
     * anything sending settings to those forms goes through here.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function overlayPrices(array $settings, ?string $driver = null): array
    {
        foreach ($this->pricesForForm($driver) as $component => $currencies) {
            if (str_starts_with($component, PlanPriceBackfill::FEATURE_PREFIX)) {
                $name = substr($component, strlen(PlanPriceBackfill::FEATURE_PREFIX));
                $settings['features'][$name]['prices'] = $currencies;
            } else {
                $settings[$component]['prices'] = $currencies;
            }
        }

        return $settings;
    }

    /**
     * Prices are rows of their own, so editing one doesn't touch the plan and the
     * observers that watch the plan don't see it. Say it for them: the app
     * instances on the plan are looked at for a subscription that went out of date.
     * (A plan that no instance depends on is not noted.)
     */
    private function recordPriceChange(): void
    {
        app(DefinitionChanges::class)->record($this, 'updated', ['prices']);
    }

    private function priceRow(string $component, string $currency): ?PlanPrice
    {
        return $this->prices->first(
            fn (PlanPrice $price) => $price->component === $component && $price->currency === $currency
        );
    }

    /**
     * @return list<string>
     */
    private function enabledCurrencies(): array
    {
        return json_decode(SettingsFacade::get('enabled_currencies', '["USD"]'), true) ?: ['USD'];
    }

    /**
     * @return list<string>
     */
    private function priceCurrencies(string $currency): array
    {
        $default = SettingsFacade::get('default_currency', 'USD');

        return $currency === $default ? [$currency] : [$currency, $default];
    }
}
