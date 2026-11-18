<?php

use App\AppPlan;
use App\Plan;
use App\PlanPrice;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\DB;

// Plan and AppPlan share the same price behaviour (HasPlanPrices), so every test runs on both.
dataset('priced_plans', ['plan', 'app plan']);

function pricedPlan(string $kind): Plan|AppPlan
{
    return $kind === 'plan' ? Plan::factory()->create() : AppPlan::factory()->create();
}

it('reads the id and amount stored for the requested currency', function (string $kind) {
    $plan = pricedPlan($kind);

    $plan->setPrice('base', 'USD', 10, 'usd_base');
    $plan->setPrice('base', 'CAD', 14, 'cad_base');

    expect($plan->fresh()->priceFor('base', 'CAD'))->toBe(['amount' => 14.0, 'price_id' => 'cad_base']);
    expect($plan->fresh()->priceFor('base', 'USD'))->toBe(['amount' => 10.0, 'price_id' => 'usd_base']);
})->with('priced_plans');

it('falls back to the default currency when the requested one has no id', function (string $kind) {
    $plan = pricedPlan($kind);

    $plan->setPrice('base', 'USD', 10, 'usd_base');
    $plan->setPrice('base', 'CAD', 14, null); // amount shown, but nothing to bill against

    expect($plan->fresh()->priceFor('base', 'CAD'))->toBe(['amount' => 10.0, 'price_id' => 'usd_base']);
})->with('priced_plans');

it('falls back to the flat settings of a plan that has no rows', function (string $kind) {
    $plan = pricedPlan($kind);

    $plan->settings = ['base' => ['price' => 7, 'price_id' => 'legacy_base'], 'features' => ['deck' => ['price' => 3, 'price_id' => 'legacy_deck']]];
    $plan->save();

    expect($plan->priceFor('base', 'USD'))->toBe(['amount' => 7, 'price_id' => 'legacy_base']);
    expect($plan->featurePriceId('deck', 'USD'))->toBe('legacy_deck');
    expect($plan->featurePriceAmount('deck', 'USD'))->toBe(3);
})->with('priced_plans');

it('keeps one id per billing driver on the same row', function (string $kind) {
    $plan = pricedPlan($kind);

    $plan->setPrice('standard', 'USD', 5, 'price_stripe', 'stripe');
    $plan->setPrice('standard', 'USD', 5, 'code_lago', 'lago');

    expect($plan->prices()->count())->toBe(1);
    expect($plan->fresh()->priceFor('standard', 'USD', 'stripe')['price_id'])->toBe('price_stripe');
    expect($plan->fresh()->priceFor('standard', 'USD', 'lago')['price_id'])->toBe('code_lago');
})->with('priced_plans');

it('reads and writes the configured billing driver, and stripe when none is configured', function (string $kind) {
    $plan = pricedPlan($kind);

    config(['billing.default' => null]);
    $plan->setPrice('base', 'USD', 1, 'unconfigured_id');
    expect($plan->prices()->first()->external_ids)->toBe(['stripe' => 'unconfigured_id']);

    config(['billing.default' => 'lago']);
    $plan->setPrice('base', 'USD', 1, 'lago_id');

    expect($plan->fresh()->priceFor('base', 'USD')['price_id'])->toBe('lago_id');
    expect($plan->prices()->first()->external_ids)->toBe(['stripe' => 'unconfigured_id', 'lago' => 'lago_id']);
})->with('priced_plans');

it('removes a price only when nothing is left on it', function (string $kind) {
    $plan = pricedPlan($kind);

    $plan->setPrice('base', 'USD', 5, 'price_stripe', 'stripe');
    $plan->setPrice('base', 'USD', 5, 'code_lago', 'lago');

    // Clearing the stripe side keeps the row: the lago id is still there
    $plan->setPrice('base', 'USD', null, null, 'stripe');
    expect($plan->prices()->count())->toBe(1);
    expect($plan->prices()->first()->amount)->toBeNull();
    expect($plan->prices()->first()->external_ids)->toBe(['lago' => 'code_lago']);

    $plan->setPrice('base', 'USD', '', '', 'lago');
    expect($plan->prices()->count())->toBe(0);
})->with('priced_plans');

it('applies the admin form input and ignores components it does not mention', function (string $kind) {
    $plan = pricedPlan($kind);

    $plan->setPrice('email', 'USD', 9, 'email_id');

    $plan->syncPrices([
        'base' => ['USD' => ['amount' => '12.50', 'price_id' => 'base_id'], 'CAD' => ['amount' => '', 'price_id' => '']],
        'standard' => ['USD' => ['amount' => 3, 'price_id' => null]],
    ]);

    expect(storedPrice($plan, 'base', 'USD'))->toBe(12.5);
    expect(storedPrice($plan, 'base', 'USD', 'price_id'))->toBe('base_id');
    expect(storedPrice($plan, 'base', 'CAD'))->toBeNull();
    expect(storedPrice($plan, 'standard', 'USD'))->toBe(3.0);
    expect(storedPrice($plan, 'email', 'USD', 'price_id'))->toBe('email_id');
    expect($plan->prices()->count())->toBe(3);
})->with('priced_plans');

it('lays prices over settings in the shape the admin forms read', function (string $kind) {
    $plan = pricedPlan($kind);

    $plan->setPrice('base', 'USD', 10, 'usd_base');
    $plan->setPrice('base', 'CAD', 14, 'cad_base');
    $plan->setPrice('feature.deck', 'USD', 2, 'deck_id');

    $settings = $plan->fresh()->overlayPrices(['base' => ['max' => 4], 'features' => ['deck' => ['status' => 'optional']]]);

    expect($settings['base']['max'])->toBe(4);
    // The database does not promise an order for the currencies, so compare without one
    expect($settings['base']['prices'])->toEqual([
        'USD' => ['amount' => 10.0, 'price_id' => 'usd_base'],
        'CAD' => ['amount' => 14.0, 'price_id' => 'cad_base'],
    ]);
    expect($settings['features']['deck']['status'])->toBe('optional');
    expect($settings['features']['deck']['prices']['USD']['price_id'])->toBe('deck_id');
})->with('priced_plans');

it('copies every price to a clone, ids for every driver included', function (string $kind) {
    $plan = pricedPlan($kind);

    $plan->setPrice('base', 'USD', 10, 'price_stripe', 'stripe');
    $plan->setPrice('base', 'USD', 10, 'code_lago', 'lago');
    $plan->setPrice('storage', 'CAD', 2, 'cad_storage', 'stripe');

    $clone = $plan->replicate();
    $clone->save();
    $plan->copyPricesTo($clone);

    expect($clone->prices()->count())->toBe(2);
    expect($clone->fresh()->priceFor('base', 'USD', 'lago')['price_id'])->toBe('code_lago');
    expect($clone->fresh()->priceFor('storage', 'CAD', 'stripe')['price_id'])->toBe('cad_storage');
    // The original is untouched
    expect($plan->prices()->count())->toBe(2);
})->with('priced_plans');

it('keeps feature rows in step with the flat feature price the form submits', function () {
    $plan = AppPlan::factory()->create();
    $plan->settings = ['features' => ['deck' => ['price' => 1, 'price_id' => 'old_flat']]];
    $plan->save();
    $plan->setPrice('feature.deck', 'USD', 1, 'old_row');
    $plan->setPrice('feature.deck', 'CAD', 2, 'cad_row');

    $plan->syncFeaturePrices(['deck' => ['status' => 'optional', 'price' => 4, 'price_id' => 'new_id'], 'spreed' => 'on']);

    // The default currency follows the form, so a stale row cannot shadow the new price
    expect($plan->fresh()->featurePriceId('deck', 'USD'))->toBe('new_id');
    expect($plan->fresh()->featurePriceAmount('deck', 'USD'))->toBe(4.0);
    // Other currencies are left alone, and a feature with no price gets no row
    expect($plan->fresh()->featurePriceId('deck', 'CAD'))->toBe('cad_row');
    expect(storedPrice($plan, 'feature.spreed', 'USD'))->toBeNull();
    expect($plan->prices()->where('component', 'feature.spreed')->count())->toBe(0);
});

it('stores the owner under a short alias, not a class name', function (string $kind) {
    $plan = pricedPlan($kind);

    $plan->setPrice('base', 'USD', 1, 'id');

    $expected = $plan instanceof AppPlan ? 'app_plan' : 'plan';
    expect(DB::table('plan_prices')->value('priceable_type'))->toBe($expected);
    expect(PlanPrice::first()->priceable->is($plan))->toBeTrue();
})->with('priced_plans');

it('shows the amount of a price that has no id for the billing driver', function (string $kind) {
    $plan = pricedPlan($kind);
    $plan->setPrice('base', 'USD', 10, null);       // priced, but not set up in the billing engine
    $plan->setPrice('base', 'CAD', 14, null);
    $plan->setPrice('standard', 'USD', 3, 'std_id');

    // priceFor() is about what can be billed, so it has nothing for these
    expect($plan->fresh()->priceFor('base', 'CAD')['price_id'])->toBeNull();

    // amountFor() is about what the plan costs
    expect($plan->fresh()->amountFor('base', 'CAD'))->toBe(14.0);
    expect($plan->fresh()->amountFor('base', 'EUR'))->toBe(10.0);   // falls back to the default currency
    expect($plan->fresh()->amountFor('base'))->toBe(10.0);          // and the default currency is the default
    expect($plan->fresh()->amountFor('standard', 'CAD'))->toBe(3.0);
    expect($plan->fresh()->amountFor('email', 'CAD'))->toBeNull();
})->with('priced_plans');

it('falls back to the flat price for the amount of a plan that has no rows', function (string $kind) {
    $plan = pricedPlan($kind);
    $plan->settings = ['base' => ['price' => 7]];
    $plan->save();

    expect($plan->amountFor('base', 'CAD'))->toBe(7);
})->with('priced_plans');

it('says whether setting a price changed anything', function (string $kind) {
    $plan = pricedPlan($kind);

    expect($plan->setPrice('base', 'USD', 10, 'usd_base'))->toBeTrue();       // new
    expect($plan->setPrice('base', 'USD', '10.00', 'usd_base'))->toBeFalse(); // same, however it is written
    expect($plan->setPrice('base', 'USD', 12, 'usd_base'))->toBeTrue();       // amount
    expect($plan->setPrice('base', 'USD', 12, 'other'))->toBeTrue();          // id
    expect($plan->setPrice('base', 'USD', null, null))->toBeTrue();           // removed
    expect($plan->setPrice('base', 'USD', null, null))->toBeFalse();          // already gone
    expect($plan->setPrice('email', 'CAD', '', ''))->toBeFalse();             // blank input for a price that never existed
})->with('priced_plans');

it('saves feature prices per enabled currency, and says whether any changed', function () {
    Settings::update('enabled_currencies', json_encode(['USD', 'CAD']));
    $plan = AppPlan::factory()->create();
    $features = ['deck' => ['status' => 'optional', 'prices' => [
        'USD' => ['amount' => 2, 'price_id' => 'usd_deck'],
        'CAD' => ['amount' => 3, 'price_id' => 'cad_deck'],
        'EUR' => ['amount' => 4, 'price_id' => 'eur_deck'],   // not enabled
    ]]];

    expect($plan->syncFeaturePrices($features))->toBeTrue();
    expect($plan->syncFeaturePrices($features))->toBeFalse();   // nothing new the second time

    expect($plan->fresh()->featurePriceId('deck', 'USD'))->toBe('usd_deck');
    expect($plan->fresh()->featurePriceId('deck', 'CAD'))->toBe('cad_deck');
    expect($plan->prices()->where('currency', 'EUR')->count())->toBe(0);
});

it('leaves the other currencies of a feature alone when only the flat price is sent', function () {
    $plan = AppPlan::factory()->create();
    $plan->setPrice('feature.deck', 'CAD', 3, 'cad_deck');

    $plan->syncFeaturePrices(['deck' => ['price' => 2, 'price_id' => 'usd_deck']]);

    expect($plan->fresh()->featurePriceId('deck', 'USD'))->toBe('usd_deck');
    expect($plan->fresh()->featurePriceId('deck', 'CAD'))->toBe('cad_deck');
});
