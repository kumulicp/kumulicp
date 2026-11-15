<?php

use App\Integrations\Billing\Stripe\StripeGateway;
use App\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Insert a Cashier subscription row. `$ends_at` / `$trial_ends_at` are
 * relative offsets ('-1 day'), resolved here so datasets don't touch the
 * clock before the app has booted.
 */
function addStripeSubscription(Organization $organization, ?string $ends_at = null, ?string $trial_ends_at = null): void
{
    DB::table('subscriptions')->insert([
        'organization_id' => $organization->id,
        'name' => 'default',
        'type' => 'default',
        'stripe_id' => 'sub_fake_'.$organization->id,
        'stripe_status' => $ends_at && now()->modify($ends_at)->isPast() ? 'canceled' : 'active',
        'ends_at' => $ends_at ? now()->modify($ends_at) : null,
        'trial_ends_at' => $trial_ends_at ? now()->modify($trial_ends_at) : null,
        'created_at' => now()->subDays(60),
        'updated_at' => now(),
    ]);
}

it('reports the state of a stripe subscription', function (?string $ends_at, ?string $trial_ends_at, string $key, string $label) {
    $organization = Organization::factory()->create();
    addStripeSubscription($organization, $ends_at, $trial_ends_at);

    $gateway = new StripeGateway($organization);

    expect($gateway->status('key'))->toBe($key);
    expect($gateway->status())->toBe(__("labels.{$label}"));
})->with([
    'running' => [null, null, 'active', 'active'],
    'on trial' => [null, '+10 days', 'trial_period', 'trial_period'],
    'cancelled but still in its grace period' => ['+10 days', null, 'grace_period', 'grace_period'],
    // Regression: Cashier's subscribed() is false once a subscription has
    // ended, so these used to fall through and report 'active'.
    'ended' => ['-1 day', null, 'ended', 'cancelled'],
    'ended with a trial date still in the future' => ['-1 day', '+10 days', 'ended', 'cancelled'],
]);

it('reports active for an organization with no stripe subscription', function () {
    $gateway = new StripeGateway(Organization::factory()->create());

    expect($gateway->status('key'))->toBe('active');
});
