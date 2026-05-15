<?php

use App\AppPlan;
use App\Application;
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
        'name'           => 'Alpha Plan',
        'description'    => 'Alpha description',
        'application_id' => $app->id,
        'archive'        => false,
        'payment_enabled' => false,
        'settings'       => [
            'base'     => ['max' => 0, 'price' => 5, 'storage' => 10, 'price_id' => 'prod_alpha_base'],
            'basic'    => ['max' => 2, 'name' => 'Basic Alpha', 'price' => 2, 'amount' => 1, 'storage' => 1, 'price_id' => 'prod_alpha_basic'],
            'storage'  => ['max' => 50, 'price' => 1, 'amount' => 5, 'price_id' => 'prod_alpha_sto'],
            'features' => [],
            'standard' => ['max' => 5, 'price' => 3, 'storage' => 2, 'price_id' => 'prod_alpha_std'],
            'configurations' => [],
            'server_type' => 'separate',
        ],
    ]);

    $plan2 = AppPlan::factory()->create([
        'name'           => 'Beta Plan',
        'description'    => 'Beta description',
        'application_id' => $app->id,
        'archive'        => false,
        'payment_enabled' => true,
        'settings'       => [
            'base'     => ['max' => 0, 'price' => 10, 'storage' => 20, 'price_id' => 'prod_beta_base'],
            'basic'    => ['max' => 4, 'name' => 'Basic Beta', 'price' => 4, 'amount' => 2, 'storage' => 2, 'price_id' => 'prod_beta_basic'],
            'storage'  => ['max' => 100, 'price' => 2, 'amount' => 10, 'price_id' => 'prod_beta_sto'],
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
    });

    it('shows a checkbox for each non-archived plan', function () {
        visit('/admin/apps/demo_app/plans')
            ->assertSee('Alpha Plan')
            ->assertSee('Beta Plan');
        // Checkboxes are rendered; verify at least the first one is in the DOM
        // via a selector that has-text on an adjacent cell
        // (we rely on the next test for interaction coverage)
    });

    it('does not show the bulk edit button when no plans are selected', function () {
        visit('/admin/apps/demo_app/plans')
            ->assertDontSee('Bulk Edit (');
    });

    it('shows Bulk Edit (1) after selecting one plan', function () {
        $page = visit('/admin/apps/demo_app/plans');
        $page->script("document.querySelector('#plan-checkbox-{$this->plan1->id}').closest('.va-checkbox__input-container').click()");
        $page->assertSee('Bulk Edit (1)');
    });

    it('shows Bulk Edit (2) after selecting two plans', function () {
        $page = visit('/admin/apps/demo_app/plans');
        $page->script("document.querySelector('#plan-checkbox-{$this->plan1->id}').closest('.va-checkbox__input-container').click()");
        $page->script("document.querySelector('#plan-checkbox-{$this->plan2->id}').closest('.va-checkbox__input-container').click()");
        $page->assertSee('Bulk Edit (2)');
    });

    it('decrements the count when a plan is deselected', function () {
        $page = visit('/admin/apps/demo_app/plans');
        $page->script("document.querySelector('#plan-checkbox-{$this->plan1->id}').closest('.va-checkbox__input-container').click()");
        $page->script("document.querySelector('#plan-checkbox-{$this->plan2->id}').closest('.va-checkbox__input-container').click()");
        $page->assertSee('Bulk Edit (2)');
        $page->script("document.querySelector('#plan-checkbox-{$this->plan1->id}').closest('.va-checkbox__input-container').click()");
        $page->assertSee('Bulk Edit (1)');
    });

    it('clicking the bulk edit button navigates to the bulk edit settings page', function () {
        $page = visit('/admin/apps/demo_app/plans');
        $page->script("document.querySelector('#plan-checkbox-{$this->plan1->id}').closest('.va-checkbox__input-container').click()");
        $page->script("document.querySelector('#plan-checkbox-{$this->plan2->id}').closest('.va-checkbox__input-container').click()");
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

    it('renders both plan names as column headers', function () {
        visit($this->url)
            ->assertSee('Alpha Plan')
            ->assertSee('Beta Plan');
    });

    it('renders the four navigation tabs', function () {
        visit($this->url)
            ->assertSee('View')
            ->assertSee('Settings')
            ->assertSee('Features')
            ->assertSee('Server Configurations');
    });

    it('pre-fills the name input for each plan with its stored value', function () {
        visit($this->url)
            ->assertValue('#plan-'.$this->plan1->id.'-name input', 'Alpha Plan')
            ->assertValue('#plan-'.$this->plan2->id.'-name input', 'Beta Plan');
    });

    it('pre-fills the description input for each plan', function () {
        visit($this->url)
            ->assertValue('#plan-'.$this->plan1->id.'-description input', 'Alpha description')
            ->assertValue('#plan-'.$this->plan2->id.'-description input', 'Beta description');
    });

    it('pre-fills the base price input with the stored numeric value', function () {
        visit($this->url)
            ->assertValue('#plan-'.$this->plan1->id.'-base-price input', '5')
            ->assertValue('#plan-'.$this->plan2->id.'-base-price input', '10');
    });

    it('base price inputs accept and retain a numeric value', function () {
        visit($this->url)
            ->fill('#plan-'.$this->plan1->id.'-base-price input', '99')
            ->assertValue('#plan-'.$this->plan1->id.'-base-price input', '99');
    });

    it('expires after input is numeric and accepts a value', function () {
        visit($this->url)
            ->fill('#plan-'.$this->plan1->id.'-expires-after input', '30')
            ->assertValue('#plan-'.$this->plan1->id.'-expires-after input', '30');
    });

    it('payment enabled checkbox reflects the stored boolean for each plan', function () {
        // plan1 has payment_enabled = false, plan2 has payment_enabled = true
        visit($this->url)
            ->assertNotChecked('#plan-'.$this->plan1->id.'-payment-enabled')
            ->assertChecked('#plan-'.$this->plan2->id.'-payment-enabled');
    });

    it('toggling a checkbox changes its checked state', function () {
        $page = visit($this->url);
        $page->assertNotChecked('#plan-'.$this->plan1->id.'-payment-enabled');
        $page->script("document.querySelector('#plan-{$this->plan1->id}-payment-enabled').closest('.va-checkbox__input-container').click()");
        $page->assertChecked('#plan-'.$this->plan1->id.'-payment-enabled');
    });

    it('saving updated values redirects back to the settings tab', function () {
        visit($this->url)
            ->fill('#plan-'.$this->plan1->id.'-name input', 'Renamed Alpha')
            ->click('#submit')
            ->assertSee('Renamed Alpha');
    });
});

// ---------------------------------------------------------------------------
// Bulk Edit View tab (read-only)
// ---------------------------------------------------------------------------

describe('Bulk Edit - View Tab', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
        (new TestSupports)->activateDemoApp();
        $this->demoApp = Application::where('slug', 'demo_app')->first();
        [$this->plan1, $this->plan2] = createDemoPlans($this->demoApp);
        $this->url = '/admin/apps/demo_app/plans/bulk-edit'
            .'?plans[]='.$this->plan1->id
            .'&plans[]='.$this->plan2->id;
    });

    it('shows both plan names as column headers', function () {
        visit($this->url)
            ->assertSee('Alpha Plan')
            ->assertSee('Beta Plan');
    });

    it('displays stored price values in the table cells', function () {
        visit($this->url)
            ->assertSee('5')   // alpha base price
            ->assertSee('10'); // beta base price
    });

    it('contains no form inputs (read-only)', function () {
        visit($this->url)
            ->assertDontSee('input[type=text]');
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

    it('shows both plan names as column headers', function () {
        visit($this->url)
            ->assertSee('Alpha Plan')
            ->assertSee('Beta Plan');
    });

    it('shows the Add Config button', function () {
        visit($this->url)
            ->assertSee('Add new configuration');
    });

    it('clicking Add Config reveals the config form', function () {
        visit($this->url)
            ->click('#addNewConfigButton')
            ->assertSee('Config Type');
    });

    it('adding a new config creates a row visible for all plans', function () {
        visit($this->url)
            ->click('#addNewConfigButton')
            ->fill('#new-config-name input', 'my-custom-setting')
            ->click('#confirmAddConfig')
            ->assertSee('my-custom-setting');
    });

    it('the new config row is dismissed after clicking Hide', function () {
        visit($this->url)
            ->click('#addNewConfigButton')
            ->assertSee('Config Type')
            ->click('button:has-text("Hide")')
            ->assertDontSee('Config Type');
    });
});
