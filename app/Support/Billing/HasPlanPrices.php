<?php

namespace App\Support\Billing;

use App\PlanPrice;
use App\Support\Facades\Settings as SettingsFacade;
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
     */
    public function setPrice(string $component, string $currency, mixed $amount, ?string $externalId, ?string $driver = null): void
    {
        $driver ??= PlanPrice::activeDriver();
        $amount = ($amount === '' || $amount === null) ? null : $amount;
        $externalId = ($externalId === '' || $externalId === null) ? null : $externalId;

        $row = $this->prices()->where('component', $component)->where('currency', $currency)->first();

        $externalIds = $row->external_ids ?? [];
        if ($externalId === null) {
            unset($externalIds[$driver]);
        } else {
            $externalIds[$driver] = $externalId;
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
    }

    /**
     * Apply the admin form's price input: [component => [currency => ['amount' => , 'price_id' => ]]].
     * Only the components and currencies present in the input are changed.
     *
     * @param  array<string, array<string, array{amount?: mixed, price_id?: mixed}>>  $prices
     */
    public function syncPrices(array $prices, ?string $driver = null): void
    {
        foreach ($prices as $component => $currencies) {
            foreach ($currencies as $currency => $entry) {
                $this->setPrice($component, $currency, $entry['amount'] ?? null, $entry['price_id'] ?? null, $driver);
            }
        }
    }

    /**
     * Keep the default-currency price rows of optional features in step with the
     * flat price/price_id the features form submits. Rows in other currencies are
     * left alone.
     *
     * @param  array<string, mixed>  $features  feature name => submitted feature (or 'on'/'off')
     */
    public function syncFeaturePrices(array $features): void
    {
        $currency = SettingsFacade::get('default_currency', 'USD');

        foreach ($features as $name => $feature) {
            $feature = is_array($feature) ? $feature : [];

            $this->setPrice(
                PlanPriceBackfill::featureComponent((string) $name),
                $currency,
                $feature['price'] ?? null,
                $feature['price_id'] ?? null,
            );
        }
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
        $this->prices()->where('component', $component)->delete();
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

    private function priceRow(string $component, string $currency): ?PlanPrice
    {
        return $this->prices->first(
            fn (PlanPrice $price) => $price->component === $component && $price->currency === $currency
        );
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
