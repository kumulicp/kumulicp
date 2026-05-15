<?php

use App\User;
use App\Support\Facades\AccountManager;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestSupports;

describe('Billing Manager', function () {
    beforeEach(function () {
        if (env('BILLING_DRIVER') !== 'stripe') {
            $this->markTestSkipped('Requires Stripe driver');
        }

        $this->actingAs(User::where('username', 'demo')->firstOrFail());
        (new TestSupports())->addUsers();

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

    it('adds and removes a billing manager via the modal', function () {
        $page = visit('/subscription/payment')
            ->assertSee('Billing Managers')
            ->assertSee('Add Billing Manager');

        $page->click('#addBillingManager');
        $page->assertSee('Add Billing Manager');
        $page->click('#billingManager');
        $page->click('text=test user1');
        $page->click('#add');
        $page->assertSee('test user1');

        $page->click('#removetesting1');
        $page->assertSee('Remove Billing Manager');
        $page->click('#remove');
        visit('/subscription/payment')
            ->assertSee('No Billing Managers');
    });
});
