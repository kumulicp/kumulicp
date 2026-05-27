<?php

use App\Integrations\Billing\Stripe\StripeGateway;
use App\User;
use Illuminate\Support\Arr;
use Tests\Support\TestSupports;

it('compiles stripe pricing correctly', function (string $driver, string $plan_type) {
    setupAccountManagerDriver('db');
    $support = new TestSupports;
    $support->seed();
    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);

    $base_plan = $support->basePlan1OfType($plan_type);
    $base_plan->payment_enabled = true;
    $base_plan->save();

    $support->setSubscription($admin->organization, $base_plan);

    $stripe = (new StripeGateway($admin->organization))->stripePricing();

    // Package-type plans bill via the base plan; app-type plans bill per app instance instead
    if ($plan_type === 'package') {
        expect(Arr::get($stripe, 'stripe_base.quantity'))->toBe(1);
    } else {
        expect($stripe)->not->toHaveKey('stripe_base');
    }
    expect($stripe)->not->toHaveKey('stripe_basic');
    expect($stripe)->not->toHaveKey('stripe_email');
    expect($stripe)->not->toHaveKey('stripe_storage');
})->with('account_manager_drivers')->with('plan_types');
