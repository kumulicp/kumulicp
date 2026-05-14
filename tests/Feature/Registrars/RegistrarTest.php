<?php

use App\OrgDomain;
use App\Support\Facades\Domain;
use App\Tld;
use App\User;
use Tests\Support\TestSupports;

/**
 * Registrar contract tests — runs against both the 'fake' and 'namecheap' drivers.
 *
 * 'fake' iterations always run in CI (no Namecheap account needed).
 * 'namecheap' iterations skip unless REGISTRAR_DRIVER=namecheap is set.
 *
 * To run against the real Namecheap sandbox:
 *   REGISTRAR_DRIVER=namecheap php artisan test --testsuite=RegistrarIntegration
 */
beforeEach(function () {
    $support = new TestSupports;
    $support->seed();
    $this->actingAs(User::find(1));
});

it('checks domain availability', function (string $driver) {
    skipUnlessRegistrar($driver, 'fake');
    setupRegistrarDriver($driver);

    $result = Domain::registrar($driver)->check('example-'.uniqid().'.com');

    expect($result)->toHaveKeys(['available', 'is_premium_name', 'ican_fee'])
        ->and($result['available'])->toBeBool();
})->with('registrar_drivers');

it('returns pricing for a TLD', function (string $driver) {
    skipUnlessRegistrar($driver, 'fake');
    setupRegistrarDriver($driver);

    $tld = Tld::firstOrCreate(['name' => 'com'], ['default_driver' => 'namecheap']);
    $pricing = Domain::registrar($driver)->pricing($tld, 'example.com');

    expect($pricing->registrationPrice(1))->toBeFloat()->toBeGreaterThan(0)
        ->and($pricing->isPremium())->toBeBool();
})->with('registrar_drivers');

it('lists domains', function (string $driver) {
    skipUnlessRegistrar($driver, 'fake');
    setupRegistrarDriver($driver);

    $list = Domain::registrar($driver)->list();

    expect($list)->toBeArray();
})->with('registrar_drivers');

it('registers a domain', function (string $driver) {
    skipUnlessRegistrar($driver, 'fake');
    setupRegistrarDriver($driver);

    $user = User::find(1);
    $tld = Tld::firstOrCreate(['name' => 'com'], ['default_driver' => 'namecheap']);

    $org_domain = OrgDomain::factory()->create([
        'name' => 'test-'.uniqid().'.com',
        'organization_id' => $user->organization->id,
        'type' => 'managed',
        'tld_id' => $tld->id,
        'status' => 'registering',
        'source' => $driver,
    ]);

    $registrar = Domain::registrar($driver);
    $result = $registrar->register($org_domain, 1);

    $org_domain->refresh();
    expect($org_domain->status)->toBe('active')
        ->and($org_domain->registered)->toBeTrue();
})->with('registrar_drivers');

it('transfers a domain', function (string $driver) {
    skipUnlessRegistrar($driver, 'fake');
    setupRegistrarDriver($driver);

    $user = User::find(1);
    $tld = Tld::firstOrCreate(['name' => 'com'], ['default_driver' => 'namecheap']);

    $org_domain = OrgDomain::factory()->create([
        'name' => 'transfer-'.uniqid().'.com',
        'organization_id' => $user->organization->id,
        'type' => 'managed',
        'tld_id' => $tld->id,
        'status' => 'transferring',
        'source' => $driver,
    ]);

    $domain_interface = Domain::registrar($driver)->select($org_domain);
    $domain_interface->transfer('fake-epp-code');

    $org_domain->refresh();
    expect($org_domain->status)->toBe('transferring');
})->with('registrar_drivers');

// ---------------------------------------------------------------------------
// Real Namecheap tests — skip unless REGISTRAR_DRIVER=namecheap
// ---------------------------------------------------------------------------

it('registers a real domain via Namecheap', function (string $driver) {
    skipUnlessRegistrar($driver, 'namecheap');

    $user = User::find(1);
    $tld_refresh = test()->get('/admin/service/domains/tlds/refresh');

    $check_domain = [];
    while (! array_key_exists('available', $check_domain) || $check_domain['available'] == false) {
        $domain_name = fake()->domainName();
        $tld_name = Domain::getTld($domain_name);
        $tld = Tld::where('name', $tld_name)->first();
        if (! $tld) {
            continue;
        }
        $org_domain = OrgDomain::factory()->create([
            'name' => $domain_name,
            'organization_id' => $user->organization->id,
            'type' => 'managed',
            'tld_id' => $tld->id,
            'status' => 'registering',
            'source' => 'namecheap',
        ]);
        $check_domain = Domain::registrar('namecheap')->check($domain_name);
    }

    $price = Domain::registrar($org_domain)->pricing()->registrationPrice(1);
    Domain::registrar('namecheap')->register($org_domain, 1);

    $org_domain->refresh();
    expect($org_domain->status)->toBe('active');
})->with('registrar_drivers');

it('transfers a real domain via Namecheap', function (string $driver) {
    skipUnlessRegistrar($driver, 'namecheap');

    $user = User::find(1);
    $tld_refresh = test()->get('/admin/service/domains/tlds/refresh');

    $check_domain = [];
    while (! array_key_exists('available', $check_domain) || $check_domain['available'] == true) {
        $domain_name = fake()->domainName();
        $tld_name = Domain::getTld($domain_name);
        $tld = Tld::where('name', $tld_name)->first();
        if (! $tld) {
            continue;
        }
        $org_domain = OrgDomain::factory()->create([
            'name' => $domain_name,
            'organization_id' => $user->organization->id,
            'type' => 'managed',
            'tld_id' => $tld->id,
            'status' => 'transferring',
            'source' => 'namecheap',
        ]);
        $check_domain = Domain::registrar('namecheap')->check($domain_name);
    }

    $price = Domain::registrar($org_domain)->pricing()->transferPrice(1);
    $domain_interface = Domain::registrar('namecheap')->select($org_domain);
    $domain_interface->transfer('epp_code');

    $org_domain->refresh();
    expect($org_domain->status)->toBe('transferring');
})->with('registrar_drivers');
