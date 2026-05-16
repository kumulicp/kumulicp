<?php

use App\OrgDomain;
use App\Plan;
use App\Tld;
use App\User;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TestSupports;

describe('Domain Registration', function () {
    beforeEach(function () {
        setupRegistrarDriver('fake');

        $support = new TestSupports;

        $tld = Tld::firstOrCreate(
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
    // Availability check page
    // -----------------------------------------------------------------------

    it('shows the availability check page', function () {
        visit('/settings/domains/availability')
            ->assertSee('Check Availability');
    });

    it('shows available with price after checking a regular domain', function () {
        $page = visit('/settings/domains/availability');

        $page->fill('input[placeholder="example.com"]', 'mysite.com')
            ->click('button[type="submit"]')
            ->waitFor('text=is available')
            ->assertSee('is available')
            ->assertSee('9.99');
    });

    it('shows unavailable message when domain contains "unavailable"', function () {
        $page = visit('/settings/domains/availability');

        $page->fill('input[placeholder="example.com"]', 'my-unavailable-domain.com')
            ->click('button[type="submit"]')
            ->waitFor('text=not available')
            ->assertSee('not available');
    });

    it('shows an alert when checking a domain with an unrecognised TLD', function () {
        $page = visit('/settings/domains/availability');

        $page->fill('input[placeholder="example.com"]', 'example.xyz999fake')
            ->click('button[type="submit"]')
            ->waitFor('.va-alert, text=not available')
            ->assertSee('xyz999fake');
    });

    // -----------------------------------------------------------------------
    // Register page — years selector changes price
    // -----------------------------------------------------------------------

    it('shows the register page with the correct 1-year price', function () {
        // Pre-create the pending domain the way the checkout step would
        $user = User::where('username', 'demo')->firstOrFail();
        $tld = Tld::where('name', 'com')->first();
        OrgDomain::factory()->create([
            'name' => 'flowtest-browser.com',
            'organization_id' => $user->organization->id,
            'type' => 'managed',
            'tld_id' => $tld->id,
            'status' => 'pending_registration',
            'source' => 'fake',
        ]);

        visit('/settings/domains/register/flowtest-browser.com')
            ->assertSee('Total Price')
            ->assertSee('9.99');
    });

    it('updates the total price when the number of years is changed', function () {
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

        $page = visit('/settings/domains/register/yearstest.com');

        // Confirm default 1-year price
        $page->assertSee('9.99');

        // Change years to 3 via the va-select dropdown
        $page->script("
            // Open the years va-select dropdown
            const selects = document.querySelectorAll('.va-select');
            for (const s of selects) {
                const label = s.querySelector('.va-input-label, label');
                if (label && label.textContent.trim() === 'Years') {
                    s.querySelector('[role=\"button\"], .va-input__container, .va-select__content')?.click();
                    break;
                }
            }
        ");
        $page->waitFor('.va-select-option, .va-dropdown__content')
            ->click('text=3')
            ->assertSee('29.97');

        // Change to 2 years
        $page->script("
            const selects = document.querySelectorAll('.va-select');
            for (const s of selects) {
                const label = s.querySelector('.va-input-label, label');
                if (label && label.textContent.trim() === 'Years') {
                    s.querySelector('[role=\"button\"], .va-input__container, .va-select__content')?.click();
                    break;
                }
            }
        ");
        $page->waitFor('.va-select-option, .va-dropdown__content')
            ->click('text=2')
            ->assertSee('19.98');
    });

    it('registers a domain end-to-end from availability check through submission', function () {
        $user = User::where('username', 'demo')->firstOrFail();
        $tld = Tld::where('name', 'com')->first();

        // Step 1: check availability
        $page = visit('/settings/domains/availability');
        $page->fill('input[placeholder="example.com"]', 'e2e-register.com')
            ->click('button[type="submit"]')
            ->waitFor('text=is available')
            ->assertSee('is available')
            ->assertSee('9.99');

        // Step 2: proceed to checkout — creates OrgDomain (pending_registration)
        $page->click('text=Checkout Domain')
            ->assertPathIs('/settings/domains/register/e2e-register.com');

        // Step 3: fill registration form and submit
        $page->assertSee('Total Price')
            ->assertSee('9.99');

        // Accept terms and submit (form fields are pre-populated from org/user data)
        $page->script("
            document.querySelector('.va-checkbox__input, input[type=\"checkbox\"]')?.closest('.va-checkbox__input-container')?.click()
                ?? document.querySelector('[type=\"checkbox\"]')?.click();
        ");

        $page->click('text=Register')
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

    it('shows the transfer page', function () {
        visit('/settings/domains/transfer')
            ->assertSee('Transfer Domain')
            ->assertSee('Domain Name')
            ->assertSee('Auth/EPP Code');
    });

    it('shows the transfer price when a valid domain and EPP code are entered', function () {
        $page = visit('/settings/domains/transfer');

        $page->fill('input[placeholder="example.com"]', 'transfer-this.com')
            ->fill('input', 'epp-secret-code', 1) // second input = epp_code
            ->waitFor('text=8.99')
            ->assertSee('8.99');
    });

    it('submits the transfer and redirects to the domains list', function () {
        $page = visit('/settings/domains/transfer');

        $page->fill('input[placeholder="example.com"]', 'transfer-submit.com');

        // Wait for the price to load (getPrice fires on @change)
        $page->script("
            document.querySelector('input[placeholder=\"example.com\"]').dispatchEvent(new Event('change', {bubbles: true}));
        ");

        $page->waitFor('text=8.99');

        // Fill EPP code
        $page->script("
            const inputs = document.querySelectorAll('input');
            for (const inp of inputs) {
                if (inp.placeholder !== 'example.com' && inp.type !== 'hidden') {
                    inp.value = 'epp-secret-code';
                    inp.dispatchEvent(new Event('input', {bubbles: true}));
                    inp.dispatchEvent(new Event('change', {bubbles: true}));
                    break;
                }
            }
        ");

        $page->waitFor('button:not([disabled]):has-text("Transfer Domain")')
            ->click('text=Transfer Domain')
            ->assertPathIs('/settings/domains');
    });
});
