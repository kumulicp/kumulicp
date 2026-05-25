<?php

use App\OrgDomain;
use App\Tld;
use App\User;
use Tests\Support\TestSupports;

describe('Domain Registration', function () {
    beforeEach(function () {
        setupRegistrarDriver('fake');
        setupBillingDriver('fake');

        $support = new TestSupports;

        Tld::firstOrCreate(
            ['name' => 'com'],
            [
                'default_driver' => 'fake',
                'is_api_registerable' => true,
                'is_api_transferable' => true,
                'is_api_renewable' => true,
                'standard_price' => 9.99,
                'min_register_years' => 1,
                'max_register_years' => 10,
                'min_transfer_years' => 1,
                'max_transfer_years' => 10,
                'min_renew_years' => 1,
                'max_renew_years' => 10,
            ]
        );

        $plan = $support->createRegistrarPlan();
        $user = User::where('username', 'demo')->firstOrFail();
        $support->setSubscription($user->organization, $plan);
        $this->actingAs($user);
    });

    // -----------------------------------------------------------------------
    // Availability check
    // -----------------------------------------------------------------------

    it('checks availability showing available, unavailable, and unknown TLD states', function () {
        $page = visit('/settings/domains/availability')
            ->assertPathIs('/settings/domains/availability')
            ->assertSee('Check Availability');

        // Available domain
        $page->fill('#domainName input', 'mysite.com')
            ->click('#checkAvailability')
            ->waitForText('is available')
            ->assertSee('is available')
            ->assertSee('9.99');

        // Unavailable domain
        $page->fill('#domainName input', 'my-unavailable-domain.com')
            ->click('#checkAvailability')
            ->waitForText('not available')
            ->assertSee('not available');

        // Unrecognised TLD
        $page->fill('#domainName input', 'example.xyz999fake')
            ->click('#checkAvailability')
            ->waitForText('xyz999fake')
            ->assertSee('xyz999fake');
    });

    // -----------------------------------------------------------------------
    // Register page — price display and years selector
    // -----------------------------------------------------------------------

    it('shows the register page with correct price and updates it when years change', function () {
        $user = User::where('username', 'demo')->firstOrFail();
        $tld = Tld::where('name', 'com')->first();

        OrgDomain::factory()->create([
            'name' => 'yearstest.com',
            'organization_id' => $user->organization->id,
            'type' => 'managed',
            'tld_id' => $tld->id,
            'status' => 'pending_registration',
            'source' => 'fake',
        ]);

        $page = visit('/settings/domains/register/yearstest.com')
            ->assertPathIs('/settings/domains/register/yearstest.com')
            ->assertSee('Total Price')
            ->assertSee('9.99');

        $page->click('#years')
            ->click('[role=option]:text-is("3")')
            ->assertSee('29.97');

        $page->click('#years')
            ->click('[role=option]:text-is("2")')
            ->assertSee('19.98');
    });

    // -----------------------------------------------------------------------
    // End-to-end: availability → checkout → register
    // -----------------------------------------------------------------------

    it('registers a domain end-to-end from availability check through submission', function () {
        $user = User::where('username', 'demo')->firstOrFail();

        $page = visit('/settings/domains/availability');

        $page->fill('#domainName input', 'e2e-register.com')
            ->click('#checkAvailability')
            ->waitForText('is available')
            ->assertSee('is available')
            ->assertSee('9.99');

        $page->click('#checkoutDomain')
            ->assertPathIs('/settings/domains/register/e2e-register.com');

        $page->assertSee('Total Price')
            ->assertSee('9.99');

        $page->script("document.querySelector('#acceptTerms').closest('.va-checkbox__input-container').click()");

        $page->click('#submit')
            ->assertPathIs('/settings/domains');

        expect(OrgDomain::where('name', 'e2e-register.com')->exists())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// Domain Transfer
// ---------------------------------------------------------------------------

describe('Domain Transfer', function () {
    beforeEach(function () {
        setupRegistrarDriver('fake');
        setupBillingDriver('fake');

        $support = new TestSupports;

        Tld::firstOrCreate(
            ['name' => 'com'],
            [
                'default_driver' => 'fake',
                'is_api_registerable' => true,
                'is_api_transferable' => true,
                'is_api_renewable' => true,
                'standard_price' => 9.99,
                'min_register_years' => 1,
                'max_register_years' => 10,
                'min_transfer_years' => 1,
                'max_transfer_years' => 10,
                'min_renew_years' => 1,
                'max_renew_years' => 10,
            ]
        );

        $plan = $support->createRegistrarPlan();
        $user = User::where('username', 'demo')->firstOrFail();
        $support->setSubscription($user->organization, $plan);
        $this->actingAs($user);
    });

    it('shows transfer price and submits the transfer', function () {
        $page = visit('/settings/domains/transfer')
            ->assertPathIs('/settings/domains/transfer')
            ->assertSee('Transfer Domain')
            ->assertSee('Domain Name')
            ->assertSee('Auth/EPP Code');

        $page->fill('#domainName input', 'transfer-submit.com');
        $page->script("document.querySelector('#domainName input').dispatchEvent(new Event('change', {bubbles: true}))");
        $page->waitForText('8.99')
            ->assertSee('8.99');

        $page->fill('#eppCode input', 'epp-secret-code');
        $page->click('#submit')
            ->assertPathIs('/settings/domains');
    });
});
