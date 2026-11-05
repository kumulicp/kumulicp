<?php

use App\Integrations\Billing\Stripe\StripeGateway;
use App\Organization;
use App\Services\AppInstance\AppInstancePlanService;
use Tests\Support\TestSupports;

/** A plan stand-in that only exposes what the gateway reads. */
function planWithOptions(array $options): object
{
    return new class($options)
    {
        public function __construct(private array $options) {}

        public function pricingOptions(): array
        {
            return $this->options;
        }
    };
}

it('turns plan lines into stripe quantities, summing lines that share a price', function () {
    $gateway = new StripeGateway(Organization::factory()->create());

    $pricing = $gateway->pricingFromPlans([
        planWithOptions([
            'standard' => ['price_id' => 'price_user', 'quantity' => 2],
            'storage' => ['price_id' => 'price_storage', 'quantity' => 0],
            'basic' => ['price_id' => null, 'quantity' => 5],
        ]),
        planWithOptions([
            'standard' => ['price_id' => 'price_user', 'quantity' => 3],
        ]),
    ]);

    expect($pricing)->toBe(['price_user' => ['quantity' => 5]]);
});

it('includes lines nested under an app instance\'s features entry', function () {
    $gateway = new StripeGateway(Organization::factory()->create());

    $pricing = $gateway->pricingFromPlans([
        planWithOptions([
            'base' => ['price_id' => 'price_base', 'quantity' => 1],
            'features' => [
                'one' => ['name' => 'one', 'price_id' => 'price_feature', 'price' => 5, 'quantity' => 1],
                'two' => ['name' => 'two', 'price_id' => 'price_feature', 'price' => 5, 'quantity' => 1],
            ],
        ]),
    ]);

    expect($pricing)->toBe([
        'price_base' => ['quantity' => 1],
        'price_feature' => ['quantity' => 2],
    ]);
});

// Regression, against the real plan service: priced features that are active
// for an app instance were shown in the plan total but never sent to Stripe.
it('bills the priced features that are active on an app instance', function () {
    setupAccountManagerDriver('db');
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();

    $instance = $support->demo_app->instances()->first();
    $plan = $instance->plan;
    $plan->payment_enabled = true;
    $plan->settings = array_merge($plan->settings ?? [], [
        'base' => ['price' => 1, 'storage' => 1, 'price_id' => 'stripe_base'],
        'features' => [
            'enabled-feature' => ['name' => 'enabled-feature', 'price' => 2, 'status' => 'enabled', 'settings' => [], 'price_id' => 'stripe_enabled_feature'],
            'optional-feature' => ['name' => 'optional-feature', 'price' => 5, 'status' => 'optional', 'settings' => [], 'price_id' => 'stripe_optional_feature'],
            'disabled-feature' => ['name' => 'disabled-feature', 'price' => 9, 'status' => 'disabled', 'settings' => [], 'price_id' => 'stripe_disabled_feature'],
        ],
    ]);
    $plan->save();

    $gateway = new StripeGateway($instance->organization);

    // The optional feature has not been switched on for this instance yet.
    $pricing = $gateway->pricingFromPlans([new AppInstancePlanService($plan, $instance->fresh())]);

    expect($pricing)->toHaveKey('stripe_base');
    expect($pricing['stripe_enabled_feature']['quantity'])->toBe(1);
    expect($pricing)->not->toHaveKey('stripe_optional_feature');
    expect($pricing)->not->toHaveKey('stripe_disabled_feature');

    $instance->updateSetting('features.optional-feature.status', 'enabled');

    $pricing = $gateway->pricingFromPlans([new AppInstancePlanService($plan, $instance->fresh())]);

    expect($pricing['stripe_optional_feature']['quantity'])->toBe(1);
    expect($pricing)->not->toHaveKey('stripe_disabled_feature');
});
