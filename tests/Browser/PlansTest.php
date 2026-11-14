<?php

use App\Plan;
use App\Support\Facades\Settings;
use App\User;
use Tests\Support\TestSupports;

describe('Admin Plans', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
    });

    it('shows the plans list and add plan modal with type selector', function () {
        visit('/admin/service/plans')
            ->assertSee('Plans')
            ->click('#addPlan')
            ->assertSee('Add Plan')
            ->assertSee('Plan Type');
    });

    it('shows the plan edit page with type selector and no type change warning initially', function () {
        $plan = Plan::factory()->create(['type' => 'package', 'name' => 'Edit Test Plan', 'org_type' => 'none']);

        visit('/admin/service/plans/'.$plan->id)
            ->assertSee('Edit Edit Test Plan Plan')
            ->assertSee('Plan Type')
            ->assertDontSee('Changing the plan type');
    });

    it('shows a warning when the plan type is changed on edit', function () {
        $plan = Plan::factory()->create(['type' => 'package', 'name' => 'Change Type Plan', 'org_type' => 'none']);

        $page = visit('/admin/service/plans/'.$plan->id);
        $page->assertDontSee('Changing the plan type');
        $page->click('#planType');
        $page->click('text=Pay per App');
        $page->assertSee('Changing the plan type');
    });

    it('hides users and storage sections for app type plans', function () {
        $plan = Plan::factory()->create(['type' => 'app', 'name' => 'App Type Visibility Plan', 'org_type' => 'none']);

        visit('/admin/service/plans/'.$plan->id)
            ->assertSee('Base Options')
            ->assertDontSee('Standard User Options')
            ->assertDontSee('Basic User Options')
            ->assertDontSee('Additional Storage Options');
    });

    it('shows users and storage sections for package type plans', function () {
        $plan = Plan::factory()->create(['type' => 'package', 'name' => 'Package Visibility Plan', 'org_type' => 'none']);

        visit('/admin/service/plans/'.$plan->id)
            ->assertSee('Standard User Options')
            ->assertSee('Basic User Options')
            ->assertSee('Additional Storage Options');
    });

    it('app plan select allows multiple selection for pay per app plans only', function () {
        // The "Add app" button is disabled until an app is available to add
        (new TestSupports)->activateDemoApp();

        $app = Plan::factory()->create(['type' => 'app', 'name' => 'Multi Select Plan', 'org_type' => 'none']);
        $package = Plan::factory()->create(['type' => 'package', 'name' => 'Single Select Plan', 'org_type' => 'none']);

        // The caption under the app plan select tells which kind of select it is
        visit('/admin/service/plans/'.$app->id)
            ->click('#addApp')
            ->assertSee('Choose one or more app plans for users to select from')
            ->assertDontSee('Choose from any app plan or select a specific plan');

        visit('/admin/service/plans/'.$package->id)
            ->click('#addApp')
            ->assertSee('Choose from any app plan or select a specific plan')
            ->assertDontSee('Choose one or more app plans for users to select from');
    });

    it('shows and saves a price per enabled currency for a package plan', function () {
        Settings::update('enabled_currencies', json_encode(['USD', 'CAD']));

        $plan = Plan::factory()->create([
            'name' => 'Multi Currency Plan',
            'type' => 'package',
            'org_type' => 'none',
        ]);

        visit("/admin/service/plans/{$plan->id}")
            ->assertSee('Price (USD)')
            ->assertSee('Price (CAD)')
            ->fill('#base-price-USD input', '10')
            ->fill('#base-price-id-USD input', 'usd_base_id')
            ->fill('#base-price-CAD input', '14')
            ->fill('#base-price-id-CAD input', 'cad_base_id')
            ->click('#submit')
            ->assertPathIs('/admin/service/plans');

        $plan->refresh();
        expect((float) $plan->setting('base.prices.USD.amount'))->toBe(10.0);
        expect($plan->setting('base.prices.USD.price_id'))->toBe('usd_base_id');
        expect((float) $plan->setting('base.prices.CAD.amount'))->toBe(14.0);
        expect($plan->setting('base.prices.CAD.price_id'))->toBe('cad_base_id');
    });
});
