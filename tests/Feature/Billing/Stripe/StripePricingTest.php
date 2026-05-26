<?php

use App\Integrations\Billing\Stripe\StripeGateway;
use Illuminate\Support\Arr;
use Tests\Support\TestSupports;

it('compiles stripe pricing correctly', function () {
    setupAccountManagerDriver('db');
    $support = new TestSupports;
    $support->seed();
    $admin = \App\User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);

    $support->base_1->payment_enabled = true;
    $support->base_1->type = 'package';
    $support->base_1->save();

    $support->setSubscription($admin->organization, $support->base_1);

    $stripe = (new StripeGateway($admin->organization))->stripePricing();

    expect(Arr::get($stripe, 'stripe_base.quantity'))->toBe(1);
    expect($stripe)->not->toHaveKey('stripe_basic');
    expect($stripe)->not->toHaveKey('stripe_email');
    expect($stripe)->not->toHaveKey('stripe_storage');
});
