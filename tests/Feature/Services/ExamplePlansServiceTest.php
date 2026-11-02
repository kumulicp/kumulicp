<?php

use App\Plan;
use App\Services\Plans\ExamplePlansService;

it('creates three hidden, unpriced example package tiers on a fresh install', function () {
    $plans = (new ExamplePlansService)->create();

    expect($plans)->toHaveCount(3);
    expect(Plan::count())->toBe(3);

    foreach ($plans as $plan) {
        expect($plan->type)->toBe('package');
        expect($plan->status)->toBe('hidden');
        expect($plan->payment_enabled)->toBeFalse();
        expect($plan->archive)->toBeFalse();
        expect($plan->is_default)->toBeFalse();
        expect($plan->app_plans)->toBe([]);
        expect($plan->features)->toHaveCount(3);

        // No prices or Stripe price ids: those are the administrator's to fill in
        foreach (['base', 'standard', 'basic', 'storage', 'application', 'email'] as $component) {
            expect($plan->setting("{$component}.price"))->toBeNull();
            expect($plan->setting("{$component}.price_id"))->toBeNull();
            expect($plan->setting("{$component}.prices"))->toBeNull();
        }
    }
});

it('gives every example tier explicit, increasing limits', function () {
    $plans = (new ExamplePlansService)->create()->values();

    foreach (['standard.max', 'basic.max', 'application.max', 'email.max', 'email.storage'] as $limit) {
        expect($plans[0]->setting($limit))->toBeGreaterThan(0);
        expect($plans[1]->setting($limit))->toBeGreaterThan($plans[0]->setting($limit));
        expect($plans[2]->setting($limit))->toBeGreaterThan($plans[1]->setting($limit));
    }

    foreach ($plans as $plan) {
        // The volunteer cap is a whole number of bundles and volunteers get less storage than users
        expect($plan->setting('basic.max') % $plan->setting('basic.amount'))->toBe(0);
        expect($plan->setting('basic.storage'))->toBeLessThanOrEqual($plan->setting('standard.storage'));
        expect($plan->setting('storage.max'))->toBeGreaterThan(0);
        expect($plan->setting('storage.amount'))->toBeGreaterThan(0);
        expect($plan->domain_max)->toBeGreaterThan(0);
    }
});

it('orders the example tiers after any existing display order', function () {
    Plan::factory()->create(['archive' => true, 'display_order' => 4]);

    $plans = (new ExamplePlansService)->create();

    expect($plans->pluck('display_order')->all())->toBe([5, 6, 7]);
});

it('does not create example tiers twice', function () {
    $service = new ExamplePlansService;

    $service->create();

    expect($service->create())->toHaveCount(0);
    expect(Plan::count())->toBe(3);
});

it('does not create example tiers when the installation already has its own plans', function () {
    Plan::factory()->create(['archive' => false]);

    expect((new ExamplePlansService)->create())->toHaveCount(0);
    expect(Plan::count())->toBe(1);
});
