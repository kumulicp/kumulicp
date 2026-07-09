<?php

use App\Application;
use App\AppPlan;
use App\Support\Facades\Settings;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();
    (new TestSupports)->activateDemoApp();

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->demoApp = Application::where('slug', 'demo_app')->first();

    $this->appPlan = AppPlan::factory()->create([
        'name' => 'Original App Plan',
        'description' => 'Original description',
        'application_id' => $this->demoApp->id,
        'archive' => false,
        'settings' => [
            'server_type' => 'separate',
            'base' => ['max' => 0, 'price' => 5, 'storage' => 10, 'price_id' => 'prod_base'],
            'basic' => ['max' => 2, 'name' => 'Basic', 'price' => 2, 'amount' => 1, 'storage' => 1, 'price_id' => 'prod_basic'],
            'storage' => ['max' => 50, 'price' => 1, 'amount' => 5, 'price_id' => 'prod_sto'],
            'standard' => ['max' => 5, 'price' => 3, 'storage' => 2, 'price_id' => 'prod_std'],
        ],
    ]);
});

function appPlanUpdatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Updated App Plan',
        'description' => 'Updated description',
        'default' => false,
        'payment_enabled' => false,
        'server_type' => 'separate',
        'self_registration_enabled' => false,
        'domain_enabled' => false,
        'domain_max' => 0,
    ], $overrides);
}

it('renders the app plan edit page for admin', function () {
    $response = $this->actingAs($this->user)->get(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}/edit"
    );

    $response->assertStatus(200);
});

it('defaults enabled_currencies to USD when no control panel setting exists', function () {
    $response = $this->actingAs($this->user)->get(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}/edit"
    );

    $response->assertInertia(fn ($page) => $page->where('enabled_currencies', ['USD']));
});

it('updates the app plan name and description', function () {
    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}",
        appPlanUpdatePayload([
            'name' => 'Renamed App Plan',
            'description' => 'Renamed description',
        ])
    )->assertRedirect();

    $this->assertDatabaseHas('app_plans', [
        'id' => $this->appPlan->id,
        'name' => 'Renamed App Plan',
        'description' => 'Renamed description',
    ]);
});

it('updates base, standard, basic, and storage prices for a single enabled currency', function () {
    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}",
        appPlanUpdatePayload([
            'prices' => [
                'base' => ['USD' => ['amount' => 12, 'price_id' => 'usd_base']],
                'standard' => ['USD' => ['amount' => 8, 'price_id' => 'usd_std']],
                'basic' => ['USD' => ['amount' => 4, 'price_id' => 'usd_basic']],
                'storage' => ['USD' => ['amount' => 2, 'price_id' => 'usd_sto']],
            ],
        ])
    )->assertRedirect();

    $this->appPlan->refresh();

    expect($this->appPlan->setting('base.prices.USD.amount'))->toEqual(12);
    expect($this->appPlan->setting('base.prices.USD.price_id'))->toBe('usd_base');
    expect($this->appPlan->setting('standard.prices.USD.amount'))->toEqual(8);
    expect($this->appPlan->setting('basic.prices.USD.amount'))->toEqual(4);
    expect($this->appPlan->setting('storage.prices.USD.amount'))->toEqual(2);
});

// ---------------------------------------------------------------------------
// Multi-currency tests
// ---------------------------------------------------------------------------

it('saves distinct prices per enabled currency for the app plan', function () {
    Settings::update('enabled_currencies', json_encode(['USD', 'CAD']));

    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}",
        appPlanUpdatePayload([
            'prices' => [
                'base' => [
                    'USD' => ['amount' => 10, 'price_id' => 'usd_base'],
                    'CAD' => ['amount' => 14, 'price_id' => 'cad_base'],
                ],
                'standard' => [
                    'USD' => ['amount' => 5, 'price_id' => 'usd_std'],
                    'CAD' => ['amount' => 7, 'price_id' => 'cad_std'],
                ],
                'basic' => [
                    'USD' => ['amount' => 3, 'price_id' => 'usd_basic'],
                    'CAD' => ['amount' => 4, 'price_id' => 'cad_basic'],
                ],
                'storage' => [
                    'USD' => ['amount' => 1, 'price_id' => 'usd_sto'],
                    'CAD' => ['amount' => 2, 'price_id' => 'cad_sto'],
                ],
            ],
        ])
    )->assertRedirect();

    $this->appPlan->refresh();

    expect($this->appPlan->setting('base.prices.USD.amount'))->toEqual(10);
    expect($this->appPlan->setting('base.prices.CAD.amount'))->toEqual(14);
    expect($this->appPlan->setting('base.prices.USD.price_id'))->toBe('usd_base');
    expect($this->appPlan->setting('base.prices.CAD.price_id'))->toBe('cad_base');

    expect($this->appPlan->setting('standard.prices.USD.amount'))->toEqual(5);
    expect($this->appPlan->setting('standard.prices.CAD.amount'))->toEqual(7);

    expect($this->appPlan->setting('basic.prices.USD.amount'))->toEqual(3);
    expect($this->appPlan->setting('basic.prices.CAD.amount'))->toEqual(4);

    expect($this->appPlan->setting('storage.prices.USD.amount'))->toEqual(1);
    expect($this->appPlan->setting('storage.prices.CAD.amount'))->toEqual(2);
});

it('passes all enabled currencies to the app plan edit page', function () {
    Settings::update('enabled_currencies', json_encode(['USD', 'CAD', 'EUR']));

    $response = $this->actingAs($this->user)->get(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}/edit"
    );

    $response->assertInertia(fn ($page) => $page->where('enabled_currencies', ['USD', 'CAD', 'EUR']));
});

it('rejects a currency amount that is not numeric for the app plan', function () {
    Settings::update('enabled_currencies', json_encode(['USD', 'CAD']));

    $this->actingAs($this->user)->post(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->appPlan->id}",
        appPlanUpdatePayload([
            'prices' => [
                'base' => [
                    'USD' => ['amount' => 10, 'price_id' => 'usd_base'],
                    'CAD' => ['amount' => 'not-a-number', 'price_id' => 'cad_base'],
                ],
            ],
        ])
    )->assertSessionHasErrors('prices.base.CAD.amount');
});
