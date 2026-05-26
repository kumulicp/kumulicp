<?php

use App\Organization;
use App\Plan;
use App\User;

describe('Fake Payment Subscription', function () {
    beforeEach(function () {
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
        $this->organization = Organization::find(1);

        // allAvailable() filters by org_type matching the organization's type ('superaccount').
        // The current plan must also match so the "Change Plan" button appears (count > 1).
        $this->organization->plan->update(['org_type' => 'superaccount']);

        // Set billing driver to 'fake' so FakePaymentMethod widget is rendered
        // and FakeBillingGateway (app-level) handles updateDefaultPaymentMethod.
        config(['billing.default' => 'fake']);
    });

    it('can subscribe to a payment-enabled plan using the fake payment widget', function () {
        $paidPlan = Plan::factory()->create([
            'name'            => 'Paid Plan',
            'description'     => 'A plan requiring payment',
            'org_type'        => 'superaccount',
            'payment_enabled' => true,
            'archive'         => false,
        ]);

        $orgId = $this->organization->id;

        $page = visit('/subscription/plans');
        $page->assertSee('Change Plan');
        $page->click('button:has-text("Change Plan")');
        $page->assertPathIs('/subscription/'.$orgId.'/options');
        $page->assertSee('Paid Plan');
        $page->click('#select'.$paidPlan->id);
        $page->assertPathIs('/subscription/'.$orgId.'/plans/'.$paidPlan->id);

        // The Subscribe button is hidden until a payment method is set
        $page->assertDontSee('Subscribe');

        // Fill in the fake payment form
        $page->fill('#fakeCardNumber input', '4242424242424242');
        $page->fill('#fakeCardExpiry input', '12/28');
        $page->fill('#fakeCardCvc input', '123');
        $page->click('#submitPaymentMethod');

        // After payment method is saved the Subscribe button becomes visible
        $page->assertSee('Subscribe');
        $page->click('button:has-text("Subscribe")');

        $page->assertPathIs('/subscription/'.$orgId.'/options');
    });
});
