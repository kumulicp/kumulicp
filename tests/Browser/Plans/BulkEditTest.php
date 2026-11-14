<?php

use App\Application;
use App\AppPlan;
use App\User;
use Tests\Support\TestSupports;

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Returns two freshly created, non-archived AppPlan records for the demo_app.
 * The demo_app must already be registered via activateDemoApp() before calling.
 */
function createDemoPlans(Application $app): array
{
    $plan1 = AppPlan::factory()->create([
        'name' => 'Alpha Plan',
        'description' => 'Alpha description',
        'application_id' => $app->id,
        'archive' => false,
        'payment_enabled' => false,
        'settings' => [
            'base' => ['max' => 0, 'price' => 5, 'storage' => 10, 'price_id' => 'prod_alpha_base'],
            'basic' => ['max' => 2, 'name' => 'Basic Alpha', 'price' => 2, 'amount' => 1, 'storage' => 1, 'price_id' => 'prod_alpha_basic'],
            'storage' => ['max' => 50, 'price' => 1, 'amount' => 5, 'price_id' => 'prod_alpha_sto'],
            'features' => [],
            'standard' => ['max' => 5, 'price' => 3, 'storage' => 2, 'price_id' => 'prod_alpha_std'],
            'configurations' => [],
            'server_type' => 'separate',
        ],
    ]);

    $plan2 = AppPlan::factory()->create([
        'name' => 'Beta Plan',
        'description' => 'Beta description',
        'application_id' => $app->id,
        'archive' => false,
        'payment_enabled' => true,
        'settings' => [
            'base' => ['max' => 0, 'price' => 10, 'storage' => 20, 'price_id' => 'prod_beta_base'],
            'basic' => ['max' => 4, 'name' => 'Basic Beta', 'price' => 4, 'amount' => 2, 'storage' => 2, 'price_id' => 'prod_beta_basic'],
            'storage' => ['max' => 100, 'price' => 2, 'amount' => 10, 'price_id' => 'prod_beta_sto'],
            'features' => [],
            'standard' => ['max' => 10, 'price' => 6, 'storage' => 4, 'price_id' => 'prod_beta_std'],
            'configurations' => [],
            'server_type' => 'separate',
        ],
    ]);

    return [$plan1, $plan2];
}

// ---------------------------------------------------------------------------
// Plans list: checkbox selection and bulk-edit button
// ---------------------------------------------------------------------------

describe('Plans List - Bulk Edit Selection', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
        (new TestSupports)->activateDemoApp();
        $this->demoApp = Application::where('slug', 'demo_app')->first();
        [$this->plan1, $this->plan2] = createDemoPlans($this->demoApp);
        $this->toggle = fn ($page, $plan) => $page->script("document.querySelector('#plan-checkbox-{$plan->id}').closest('.va-checkbox__input-container').click()");
    });

    it('counts the selected plans on the bulk edit button as plans are selected and deselected', function () {
        $page = visit('/admin/apps/demo_app/plans')
            ->assertSee('Alpha Plan')
            ->assertSee('Beta Plan')
            ->assertDontSee('Bulk Edit (');

        ($this->toggle)($page, $this->plan1);
        $page->assertSee('Bulk Edit (1)');

        ($this->toggle)($page, $this->plan2);
        $page->assertSee('Bulk Edit (2)');

        ($this->toggle)($page, $this->plan1);
        $page->assertSee('Bulk Edit (1)');
    });

    it('navigates to the bulk edit page for the selected plans', function () {
        $page = visit('/admin/apps/demo_app/plans');
        ($this->toggle)($page, $this->plan1);
        ($this->toggle)($page, $this->plan2);

        $page->click('#bulkEditButton')
            ->assertSee('Alpha Plan')
            ->assertSee('Beta Plan');
    });
});

// ---------------------------------------------------------------------------
// Bulk Edit Settings tab
// ---------------------------------------------------------------------------

describe('Bulk Edit - Settings Tab', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
        (new TestSupports)->activateDemoApp();
        $this->demoApp = Application::where('slug', 'demo_app')->first();
        [$this->plan1, $this->plan2] = createDemoPlans($this->demoApp);
        $this->url = '/admin/apps/demo_app/plans/bulk-edit/edit'
            .'?plans[]='.$this->plan1->id
            .'&plans[]='.$this->plan2->id;
    });

    it('renders the four tabs and a column pre-filled with the stored values of each plan', function () {
        visit($this->url)
            ->assertSee('View')
            ->assertSee('Settings')
            ->assertSee('Features')
            ->assertSee('Edit Chart Values')
            ->assertValue('#plan-'.$this->plan1->id.'-name input', 'Alpha Plan')
            ->assertValue('#plan-'.$this->plan2->id.'-name input', 'Beta Plan')
            ->assertValue('#plan-'.$this->plan1->id.'-description input', 'Alpha description')
            ->assertValue('#plan-'.$this->plan2->id.'-description input', 'Beta description')
            ->assertValue('#plan-'.$this->plan1->id.'-base-price input', '5')
            ->assertValue('#plan-'.$this->plan2->id.'-base-price input', '10')
            ->assertNotChecked('#plan-'.$this->plan1->id.'-payment-enabled')
            ->assertChecked('#plan-'.$this->plan2->id.'-payment-enabled');
    });

    it('edits each plan in its own column and saves the changes', function () {
        $page = visit($this->url)
            ->fill('#plan-'.$this->plan1->id.'-name input', 'Renamed Alpha')
            ->fill('#plan-'.$this->plan2->id.'-name input', 'Renamed Beta');
        $page->script("document.querySelector('#plan-{$this->plan1->id}-payment-enabled').closest('.va-checkbox__input-container').click()");
        $page->assertChecked('#plan-'.$this->plan1->id.'-payment-enabled')
            ->click('#submit')
            ->assertSee('Renamed Alpha')
            ->assertSee('Renamed Beta');

        expect($this->plan1->refresh()->name)->toBe('Renamed Alpha');
        expect($this->plan1->payment_enabled)->toBeTruthy();
        expect($this->plan2->refresh()->name)->toBe('Renamed Beta');
        expect($this->plan2->payment_enabled)->toBeTruthy();
    });
});

// ---------------------------------------------------------------------------
// Bulk Edit View tab (read-only)
// ---------------------------------------------------------------------------

describe('Bulk Edit - View Tab', function () {
    it('shows both plans side by side with their stored prices', function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
        (new TestSupports)->activateDemoApp();
        [$plan1, $plan2] = createDemoPlans(Application::where('slug', 'demo_app')->first());

        visit('/admin/apps/demo_app/plans/bulk-edit?plans[]='.$plan1->id.'&plans[]='.$plan2->id)
            ->assertSee('Alpha Plan')
            ->assertSee('Beta Plan')
            ->assertSee('5')
            ->assertSee('10');
    });
});

// ---------------------------------------------------------------------------
// Bulk Edit Configurations tab
// ---------------------------------------------------------------------------

describe('Bulk Edit - Configurations Tab', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
        (new TestSupports)->activateDemoApp();
        $this->demoApp = Application::where('slug', 'demo_app')->first();
        [$this->plan1, $this->plan2] = createDemoPlans($this->demoApp);
        $this->url = '/admin/apps/demo_app/plans/bulk-edit/configurations'
            .'?plans[]='.$this->plan1->id
            .'&plans[]='.$this->plan2->id;
    });

    it('adds a new config row for all plans', function () {
        visit($this->url)
            ->assertSee('Alpha Plan')
            ->assertSee('Beta Plan')
            ->assertSee('Add new configuration')
            ->click('#addNewConfigButton')
            ->assertSee('Config Type')
            ->fill('#new-config-name input', 'my-custom-setting')
            ->click('#confirmAddConfig')
            ->assertSee('my-custom-setting');
    });

    it('dismisses the new config form when Hide is clicked', function () {
        visit($this->url)
            ->click('#addNewConfigButton')
            ->assertSee('Config Type')
            ->click('button:has-text("Hide")')
            ->assertDontSee('Config Type');
    });
});
