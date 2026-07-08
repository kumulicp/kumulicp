<?php

use App\Application;
use App\AppPlan;
use App\Support\Facades\Settings;
use App\User;
use Tests\Support\TestSupports;

describe('App Plans', function () {
    beforeEach(function () {
        (new TestSupports)->activateDemoApp();

        $this->actingAs(User::where('username', 'demo')->firstOrFail());
        $this->demoApp = Application::where('slug', 'demo_app')->first();
    });

    it('shows and saves a price per enabled currency for an app plan', function () {
        Settings::update('enabled_currencies', json_encode(['USD', 'CAD']));

        $plan = AppPlan::factory()->create([
            'name' => 'Multi Currency App Plan',
            'application_id' => $this->demoApp->id,
            'archive' => false,
            'settings' => [
                'server_type' => 'separate',
                'base' => ['max' => 0, 'storage' => 10],
            ],
        ]);

        visit("/admin/apps/{$this->demoApp->slug}/plans/{$plan->id}/edit")
            ->assertSee('Price (USD)')
            ->assertSee('Price (CAD)')
            ->fill('#base-price-USD input', '10')
            ->fill('#base-price-id-USD input', 'usd_base_id')
            ->fill('#base-price-CAD input', '14')
            ->fill('#base-price-id-CAD input', 'cad_base_id')
            ->click('#submit')
            ->assertPathIs("/admin/apps/{$this->demoApp->slug}/plans/{$plan->id}");

        $plan->refresh();
        expect((float) $plan->setting('base.prices.USD.amount'))->toBe(10.0);
        expect($plan->setting('base.prices.USD.price_id'))->toBe('usd_base_id');
        expect((float) $plan->setting('base.prices.CAD.amount'))->toBe(14.0);
        expect($plan->setting('base.prices.CAD.price_id'))->toBe('cad_base_id');
    });
});
