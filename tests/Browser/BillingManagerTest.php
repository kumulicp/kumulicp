<?php

use App\User;
use App\Support\Facades\AccountManager;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestSupports;

// The billing info menu item and /subscription/payment page require the
// has-billing-account gate to pass, which checks for an active Stripe
// subscription. We set the billing driver to 'stripe' and insert a fake
// active subscription row to satisfy that check without hitting Stripe.

describe('Billing Manager', function () {
    beforeEach(function () {
        $this->actingAs(User::find(1));
        (new TestSupports())->addUsers();

        config(['billing.default' => 'stripe']);

        DB::table('subscriptions')->insert([
            'organization_id' => 1,
            'name' => 'default',
            'type' => 'default',
            'stripe_id' => 'sub_test_fake',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test_fake',
            'quantity' => 1,
            'trial_ends_at' => null,
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    it('shows the payment page with the billing managers section', function () {
        visit('/subscription/payment')
            ->assertSee('Billing Managers')
            ->assertSee('Add Billing Manager');
    });

    it('adds a billing manager via the modal', function () {
        $page = visit('/subscription/payment')
            ->assertSee('Billing Managers');

        $page->click('#addBillingManager');

        $page->assertSee('Add Billing Manager');

        // va-select renders a searchable input inside the #billingManager wrapper
        $page->fill('#billingManager input', 'test user1');
        $page->click('.va-select-option');

        $page->click('#add');

        $page->assertSee('test user1');
    });

    it('removes a billing manager after confirming', function () {
        // Pre-assign the billing_manager role directly so the user appears on the page
        $user = AccountManager::users()->find('testing1');
        $user->permissions()->addBillingManagerAccess();

        $page = visit('/subscription/payment')
            ->assertSee('test user1');

        $page->click('#removetesting1');

        $page->assertSee('Remove Billing Manager');

        $page->click('#remove');

        $page->assertDontSee('test user1');
    });
});
