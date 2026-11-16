<?php

use App\OrgDomain;
use App\User;
use Illuminate\Support\Facades\Gate;
use OffloadProject\Toggle\Facades\Toggle;
use Tests\Support\TestSupports;

beforeEach(function () {
    $support = new TestSupports;
    $support->seed();
    // The migration turns the flag on when a registrar is configured (REGISTRAR_DRIVER), which
    // would override the config these tests set. Start from no stored value.
    Toggle::delete('domain-registrar');
    $support->createBase2Plan();
    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($this->user);
    $support->setSubscription($this->user->organization, $support->base_2);
});

it('is off by default', function () {
    expect(Toggle::active('domain-registrar'))->toBeFalse();
});

it('blocks the admin domain and tld pages while the registrar feature is off', function () {
    config(['toggle.flags.domain-registrar' => false]);

    $this->get('/admin/service/domains')->assertNotFound();
    $this->get('/admin/service/domains/tlds')->assertNotFound();
    $this->get('/admin/service/domains/tlds/create')->assertNotFound();
    $this->get('/admin/service/domains/tlds/refresh')->assertNotFound();
});

it('opens the admin tld page once the registrar feature is on', function () {
    config(['toggle.flags.domain-registrar' => true]);

    $this->get('/admin/service/domains/tlds')->assertOk();
});

it('blocks registering and transferring a domain in while the registrar feature is off', function () {
    config(['toggle.flags.domain-registrar' => false]);

    $this->get('/settings/domains/availability')->assertNotFound();
    $this->post('/settings/domains/availability')->assertNotFound();
    $this->post('/settings/domains/check')->assertNotFound();
    $this->get('/settings/domains/register/example.com')->assertNotFound();
    $this->post('/settings/domains/register/example.com')->assertNotFound();
    $this->get('/settings/domains/transfer')->assertNotFound();
    $this->post('/settings/domains/transfer')->assertNotFound();
    $this->post('/settings/domains/transfer/price')->assertNotFound();
});

it('denies the registrar gates while the registrar feature is off', function () {
    config(['toggle.flags.domain-registrar' => false]);

    $domain = new OrgDomain;
    $domain->name = 'example.com';
    $domain->organization()->associate($this->user->organization);

    expect(Gate::allows('register-domains'))->toBeFalse()
        ->and(Gate::allows('transfer-domains'))->toBeFalse()
        ->and(Gate::allows('transfer-in-domain', $domain))->toBeFalse()
        ->and(Gate::allows('renew-domain', $domain))->toBeFalse()
        ->and(Gate::allows('reactivate-domain', $domain))->toBeFalse()
        ->and(Gate::allows('request-domain-transfer', $domain))->toBeFalse()
        ->and(Gate::inspect('register-domains')->message())->toBe(__('organization.domain.denied.registrar_disabled'));
});

it('still lets an organization connect a domain it owns while the registrar feature is off', function () {
    config(['toggle.flags.domain-registrar' => false]);

    $this->get('/settings/domains')->assertOk();
    $this->get('/settings/domains/connect')->assertOk();

    $this->post('/settings/domains/connect', ['domain_name' => 'example.com'])->assertSessionHasNoErrors();

    $domain = OrgDomain::where('name', 'example.com')->firstOrFail();
    expect($domain->type)->toBe('connection')
        ->and($domain->tld_id)->toBeNull();
});

it('only offers the connect option on the domains page while the registrar feature is off', function () {
    config(['toggle.flags.domain-registrar' => false]);

    $this->get('/settings/domains')->assertInertia(fn ($page) => $page
        ->where('can.connect_domains', true)
        ->where('can.register_domains', false)
        ->where('can.transfer_domains', false));
});

it('shares the flag with the frontend when it is off', function () {
    config(['toggle.flags.domain-registrar' => false]);

    $this->get('/settings/domains')->assertInertia(fn ($page) => $page->where('flags.domainRegistrar', false));
});

it('shares the flag with the frontend when it is on', function () {
    config(['toggle.flags.domain-registrar' => true]);

    $this->get('/settings/domains')->assertInertia(fn ($page) => $page->where('flags.domainRegistrar', true));
});
