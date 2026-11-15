<?php

use App\Support\Billing\PlanPriceBackfill;

it('moves per-currency prices into rows and strips them from settings', function () {
    $result = PlanPriceBackfill::extract([
        'base' => ['price_id' => 'flat_base', 'prices' => [
            'USD' => ['amount' => '10', 'price_id' => 'usd_base'],
            'CAD' => ['amount' => 14, 'price_id' => 'cad_base'],
        ]],
        'configurations' => ['redis' => ['enabled' => true]],
    ], 'USD');

    expect($result['rows'])->toHaveCount(2);
    expect(collect($result['rows'])->firstWhere('currency', 'USD'))->toMatchArray([
        'component' => 'base', 'amount' => '10', 'external_id' => 'usd_base',
    ]);
    expect($result['settings']['base'])->not->toHaveKey('prices');
    expect($result['settings']['base']['price_id'])->toBe('flat_base');
    expect($result['settings']['configurations'])->toBe(['redis' => ['enabled' => true]]);
});

it('files a flat price id under the default currency only when it has no row of its own', function () {
    $flatOnly = PlanPriceBackfill::extract(['standard' => ['price' => 3, 'price_id' => 'std_flat']], 'CAD');
    expect($flatOnly['rows'])->toBe([
        ['component' => 'standard', 'currency' => 'CAD', 'amount' => 3, 'external_id' => 'std_flat'],
    ]);

    $both = PlanPriceBackfill::extract(['standard' => [
        'price_id' => 'std_flat',
        'prices' => ['USD' => ['amount' => 5, 'price_id' => 'std_usd']],
    ]], 'USD');
    expect($both['rows'])->toHaveCount(1);
    expect($both['rows'][0]['external_id'])->toBe('std_usd');
});

it('skips blank prices and an amount-only flat price, and nulls a non-numeric amount', function () {
    $result = PlanPriceBackfill::extract([
        'basic' => ['price' => 5, 'price_id' => null],
        'storage' => ['prices' => ['USD' => ['amount' => '', 'price_id' => '']]],
        'application' => ['prices' => ['USD' => ['amount' => 'abc', 'price_id' => 'app_id']]],
        'email' => [],
        'base' => 'unexpected',
    ], 'USD');

    expect($result['rows'])->toBe([
        ['component' => 'application', 'currency' => 'USD', 'amount' => null, 'external_id' => 'app_id'],
    ]);
});

it('extracts optional feature prices as feature.{name} components', function () {
    $result = PlanPriceBackfill::extract(['features' => [
        'deck' => ['status' => 'optional', 'price' => '2.50', 'price_id' => 'deck_flat'],
        'spreed' => ['price_id' => null, 'prices' => ['CAD' => ['amount' => 4, 'price_id' => 'spreed_cad']]],
        'onlyoffice' => 'on',
    ]], 'USD');

    $rows = collect($result['rows'])->keyBy(fn ($row) => $row['component'].'/'.$row['currency']);

    expect($rows)->toHaveCount(2);
    expect($rows['feature.deck/USD']['external_id'])->toBe('deck_flat');
    expect($rows['feature.spreed/CAD']['external_id'])->toBe('spreed_cad');
    expect($result['settings']['features']['spreed'])->not->toHaveKey('prices');
});

it('is a no-op the second time', function () {
    $once = PlanPriceBackfill::extract(['base' => ['prices' => ['USD' => ['amount' => 1, 'price_id' => 'a']]]], 'USD');
    $twice = PlanPriceBackfill::extract($once['settings'], 'USD');

    expect($twice['settings'])->toBe($once['settings']);
    expect($twice['rows'])->toBe([]);
});

it('restores rows into settings the way they were read', function () {
    $original = ['base' => ['prices' => ['CAD' => ['amount' => 14, 'price_id' => 'cad_base']]], 'features' => ['deck' => ['price_id' => null, 'prices' => ['USD' => ['amount' => 2, 'price_id' => 'deck_usd']]]]];
    $extracted = PlanPriceBackfill::extract($original, 'USD');

    $restored = PlanPriceBackfill::restore($extracted['settings'], $extracted['rows']);

    expect($restored['base']['prices'])->toBe(['CAD' => ['amount' => 14, 'price_id' => 'cad_base']]);
    expect($restored['features']['deck']['prices']['USD'])->toBe(['amount' => 2, 'price_id' => 'deck_usd']);
});
