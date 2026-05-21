<?php

use App\Organization;
use App\Plan;
use App\Services\Organization\BasePlanService;
use App\Support\Facades\Organization as OrganizationFacade;

// The Feature/Subscription beforeEach in Pest.php already seeds, activates demo app,
// creates demo app plans, creates base_2, and adds users. $this->support is available.
// base_1 (type='app') has price_ids; base_2 (type='package') has no price_ids.

function makePackagePlanWithPriceIds(): Plan
{
    return Plan::factory()->create([
        'type' => 'package',
        'payment_enabled' => true,
        'settings' => [
            'base' => ['price' => 5, 'storage' => 1, 'price_id' => 'pkg_base_price', 'minimal_label' => null],
            'standard' => ['max' => 10, 'price' => 2, 'storage' => 1, 'price_id' => 'pkg_std_price'],
            'basic' => ['max' => null, 'name' => null, 'price' => null, 'amount' => null, 'storage' => null, 'price_id' => null],
            'storage' => ['max' => null, 'price' => null, 'amount' => null, 'price_id' => null],
            'email' => ['max' => null, 'price' => null, 'storage' => null, 'price_id' => null],
            'application' => ['max' => null, 'price' => null, 'price_id' => null],
        ],
    ]);
}

it('package plan exposes pricing options when price ids are configured', function () {
    $organization = Organization::find(1);
    OrganizationFacade::setOrganization($organization);
    $plan = makePackagePlanWithPriceIds();

    $service = new BasePlanService($organization, $plan);

    expect($plan->type)->toBe('package');
    expect($service->pricingOptions())->not->toBeEmpty();
});

it('app plan with price ids still returns empty pricing options due to type guard', function () {
    $organization = Organization::find(1);
    OrganizationFacade::setOrganization($organization);

    // base_1 is type='app' and has price_ids set in its settings
    $service = new BasePlanService($organization, $this->support->base_1);

    expect($this->support->base_1->type)->toBe('app');
    expect($this->support->base_1->setting('base.price_id'))->not->toBeNull();
    expect($service->pricingOptions())->toBe([]);
});

it('package plan returns non-zero total price when base price is configured', function () {
    $organization = Organization::find(1);
    OrganizationFacade::setOrganization($organization);

    $service = new BasePlanService($organization, $this->support->base_2);

    expect($this->support->base_2->type)->toBe('package');
    expect($service->totalPrice())->toBeGreaterThan(0);
});

it('app plan returns zero total price at base level', function () {
    $organization = Organization::find(1);
    OrganizationFacade::setOrganization($organization);

    // base_1 has base.price = 1 but is type='app', so should still return 0
    $service = new BasePlanService($organization, $this->support->base_1);

    expect($this->support->base_1->type)->toBe('app');
    expect($this->support->base_1->setting('base.price'))->toBeGreaterThan(0);
    expect($service->totalPrice())->toBe(0);
});

it('package plan returns stats from base plan settings when prices are configured', function () {
    $organization = Organization::find(1);
    OrganizationFacade::setOrganization($organization);

    $service = new BasePlanService($organization, $this->support->base_2);

    expect($this->support->base_2->type)->toBe('package');
    expect($service->stats())->not->toBeEmpty();
});

it('app plan returns empty stats regardless of settings', function () {
    $organization = Organization::find(1);
    OrganizationFacade::setOrganization($organization);

    $service = new BasePlanService($organization, $this->support->base_1);

    expect($this->support->base_1->type)->toBe('app');
    expect($service->stats())->toBe([]);
});

it('package plan builds stripe pricing from configured price ids', function () {
    $organization = Organization::find(1);
    OrganizationFacade::setOrganization($organization);
    $plan = makePackagePlanWithPriceIds();

    $service = new BasePlanService($organization, $plan);

    expect($plan->type)->toBe('package');
    $pricing = $service->stripePricing();
    expect($pricing)->toBeArray();
    expect(array_key_exists('pkg_base_price', $pricing))->toBeTrue();
});

it('app plan returns empty stripe pricing even when price ids are configured', function () {
    $organization = Organization::find(1);
    OrganizationFacade::setOrganization($organization);

    // base_1 has price_ids but is type='app'
    $service = new BasePlanService($organization, $this->support->base_1);

    expect($this->support->base_1->type)->toBe('app');
    expect($service->stripePricing())->toBe([]);
});
