<?php

use App\OrgDomain;
use App\Support\Facades\Domain;
use App\Tld;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    $this->support = new TestSupports;
    $this->support->seed();
    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($this->user);

    setupRegistrarDriver('fake');
    setupBillingDriver('fake');

    $this->tld = Tld::firstOrCreate(
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
});

// ---------------------------------------------------------------------------
// check() — available / unavailable / premium
// ---------------------------------------------------------------------------

it('check() returns available for a regular domain', function () {
    $result = Domain::registrar('fake')->check('my-new-domain.com');

    expect($result['available'])->toBeTrue()
        ->and($result['is_premium_name'])->toBeFalse()
        ->and($result)->toHaveKey('ican_fee');
});

it('check() returns unavailable when the domain name contains "unavailable"', function () {
    $result = Domain::registrar('fake')->check('my-unavailable-domain.com');

    expect($result['available'])->toBeFalse()
        ->and($result['is_premium_name'])->toBeFalse();
});

it('check() returns a premium domain when the name contains "premium"', function () {
    $result = Domain::registrar('fake')->check('super-premium-domain.com');

    expect($result['available'])->toBeTrue()
        ->and($result['is_premium_name'])->toBeTrue()
        ->and($result['premium_registration_price'])->toBe(99.99)
        ->and($result['premium_renewal_price'])->toBe(89.99)
        ->and($result['premium_transfer_price'])->toBe(79.99);
});

// ---------------------------------------------------------------------------
// Pricing — regular and premium
// ---------------------------------------------------------------------------

it('returns year-keyed registration prices for a regular domain', function () {
    $pricing = Domain::registrar('fake')->pricing($this->tld, 'example.com');

    $prices = $pricing->registrationPrices();

    expect($prices)->toHaveCount(10)
        ->and(array_key_exists(1, $prices))->toBeTrue()
        ->and(array_key_exists(10, $prices))->toBeTrue()
        ->and($prices[1])->toBe(9.99);
});

it('registrationPrice() for 1 year returns 9.99', function () {
    $pricing = Domain::registrar('fake')->pricing($this->tld, 'example.com');

    expect($pricing->registrationPrice(1))->toBe(9.99);
});

it('registrationPrice() for 3 years returns 29.97', function () {
    $pricing = Domain::registrar('fake')->pricing($this->tld, 'example.com');

    expect($pricing->registrationPrice(3))->toBe(29.97);
});

it('transferPrices() returns year-keyed array and [1] gives the 1-year price', function () {
    $pricing = Domain::registrar('fake')->pricing($this->tld, 'example.com');

    $prices = $pricing->transferPrices();

    expect($prices)->toHaveCount(10)
        ->and($prices[1])->toBe(8.99);
});

it('isPremium() is false for a regular domain', function () {
    $pricing = Domain::registrar('fake')->pricing($this->tld, 'example.com');

    expect($pricing->isPremium())->toBeFalse()
        ->and($pricing->premiumPrice())->toBe(99.99); // available but not used
});

it('isPremium() is true and premiumPrice() is 99.99 for a premium domain', function () {
    $pricing = Domain::registrar('fake')->pricing($this->tld, 'super-premium-domain.com');

    expect($pricing->isPremium())->toBeTrue()
        ->and($pricing->premiumPrice())->toBe(99.99);
});

// ---------------------------------------------------------------------------
// Register / transfer — direct registrar
// ---------------------------------------------------------------------------

it('registers a domain and sets status to active', function () {
    $org_domain = OrgDomain::factory()->create([
        'name' => 'test-'.uniqid().'.com',
        'organization_id' => $this->user->organization->id,
        'type' => 'managed',
        'tld_id' => $this->tld->id,
        'status' => 'registering',
        'source' => 'fake',
    ]);

    Domain::registrar('fake')->register($org_domain, 1);

    $org_domain->refresh();
    expect($org_domain->status)->toBe('active')
        ->and($org_domain->registered)->toBeTrue()
        ->and($org_domain->domain_id)->toStartWith('fake-');
});

it('transfer() sets status to transferring and records a transfer_id', function () {
    $org_domain = OrgDomain::factory()->create([
        'name' => 'transfer-'.uniqid().'.com',
        'organization_id' => $this->user->organization->id,
        'type' => 'managed',
        'tld_id' => $this->tld->id,
        'status' => 'transferring',
        'source' => 'fake',
    ]);

    Domain::registrar('fake')->select($org_domain)->transfer('epp-abc123');

    $org_domain->refresh();
    expect($org_domain->status)->toBe('transferring')
        ->and($org_domain->transfer_id)->toStartWith('fake-transfer-');
});

// ---------------------------------------------------------------------------
// HTTP — POST /settings/domains/check
// ---------------------------------------------------------------------------

describe('POST /settings/domains/check', function () {
    beforeEach(function () {
        $plan = $this->support->createRegistrarPlan();
        $this->support->setSubscription($this->user->organization, $plan);

    });

    it('returns available=true with price for a regular domain', function () {
        $this->postJson('/settings/domains/check', ['domain_name' => 'mysite.com'])
            ->assertOk()
            ->assertJson([
                'availability' => true,
                'price' => 9.99,
            ]);
    });

    it('returns available=false for a domain containing "unavailable"', function () {
        $this->postJson('/settings/domains/check', ['domain_name' => 'my-unavailable-site.com'])
            ->assertOk()
            ->assertJson(['availability' => false]);
    });

    it('returns available=false with a TLD-error message for an unrecognised TLD', function () {
        $response = $this->postJson('/settings/domains/check', ['domain_name' => 'example.xyz999fake']);

        $response->assertOk()
            ->assertJsonPath('availability', false);

        expect($response->json('message'))->toContain('xyz999fake');
    });

    it('returns available=true with the premium price for a premium domain', function () {
        // standard_price = 0 so the controller falls through to pricing()
        $this->tld->update(['standard_price' => 0]);

        $this->postJson('/settings/domains/check', ['domain_name' => 'super-premium-domain.com'])
            ->assertOk()
            ->assertJson([
                'availability' => true,
                'price' => 99.99,
            ]);
    });
});

// ---------------------------------------------------------------------------
// HTTP — POST /settings/domains/transfer/price
// ---------------------------------------------------------------------------

describe('POST /settings/domains/transfer/price', function () {
    beforeEach(function () {
        $plan = $this->support->createRegistrarPlan();
        $this->support->setSubscription($this->user->organization, $plan);

    });

    it('returns the 1-year transfer price for a known TLD', function () {
        $this->postJson('/settings/domains/transfer/price', ['domain_name' => 'transfer-test.com'])
            ->assertOk()
            ->assertJson([
                'status' => 'success',
                'price' => 8.99,
            ]);
    });

    it('returns failed_validation for an unrecognised TLD', function () {
        $this->postJson('/settings/domains/transfer/price', ['domain_name' => 'example.xyz999fake'])
            ->assertOk()
            ->assertJsonPath('status', 'failed_validation');
    });
});

// ---------------------------------------------------------------------------
// HTTP — full register + transfer flows
// ---------------------------------------------------------------------------

describe('Register flow', function () {
    beforeEach(function () {
        $plan = $this->support->createRegistrarPlan();
        $this->support->setSubscription($this->user->organization, $plan);

        // Pre-create the pending_registration domain as the select step would
        $this->pending = OrgDomain::factory()->create([
            'name' => 'flowtest.com',
            'organization_id' => $this->user->organization->id,
            'type' => 'managed',
            'tld_id' => $this->tld->id,
            'status' => 'pending_registration',
            'source' => 'fake',
        ]);
    });

    it('GET /settings/domains/register/{domain} returns the registration page', function () {
        $this->get('/settings/domains/register/flowtest.com')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Organization/Settings/WebDomains/WebDomainsNewRegister')
                ->has('standard_prices')
                ->where('tld', 'com')
                ->where('is_premium', false)
            );
    });

    it('POST /settings/domains/register/{domain} queues registration and redirects', function () {
        $this->post('/settings/domains/register/flowtest.com', [
            'years' => 1,
            'organization_name' => 'Demo',
            'email_address' => 'demo@example.com',
            'first_name' => 'Demo',
            'last_name' => 'User',
            'accept_terms' => true,
            'address_1' => '123 Demo St',
            'address_2' => '',
            'city' => 'Demotown',
            'state' => 'AZ',
            'postal_code' => '123 456',
            'country' => 'US',
            'phone' => '1234567890',
            'country_phone_code' => '+1',
            'tld' => 'com',
        ])
            ->assertRedirect('/settings/domains');
    });
});

describe('Transfer flow', function () {
    beforeEach(function () {
        $plan = $this->support->createRegistrarPlan();
        $this->support->setSubscription($this->user->organization, $plan);

    });

    it('POST /settings/domains/transfer redirects and creates the domain in transferring status', function () {
        $this->post('/settings/domains/transfer', [
            'domain_name' => 'new-transfer.com',
            'epp_code' => 'epp-test-123',
        ])
            ->assertRedirect('/settings/domains');

        expect(OrgDomain::where('name', 'new-transfer.com')->where('status', 'transferring')->exists())->toBeTrue();
    });
});
