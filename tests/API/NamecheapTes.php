<?php

use App\Actions\Domains\RegisterDomainName;
use App\Actions\Domains\TransferDomainName;
use App\OrgDomain;
use App\Support\Facades\Action;
use App\Support\Facades\Domain;
use App\Tld;
use App\User;
use Tests\Support\TestSupports;

it('registers a domain via namecheap', function () {
    if (is_null(config('domain.registrars.namecheap.url'))) {
        test()->markTestSkipped('Requires Namecheap registrar to be configured');
    }

    $support = new TestSupports;
    $support->seed();
    $user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($user);

    $this->get('/admin/service/domains/tlds/refresh');
    $check_domain = [];

    while (! array_key_exists('available', $check_domain) || $check_domain['available'] == false) {
        $domain_name = fake()->domainName();
        $tld_name = Domain::getTld($domain_name);
        $tld = Tld::where('name', $tld_name)->first();
        $domain = OrgDomain::factory()->create([
            'name' => $domain_name,
            'organization_id' => $user->organization->id,
            'type' => 'managed',
            'tld_id' => $tld->id,
            'status' => 'registering',
        ]);
        $check_domain = Domain::registrar($domain->source)->check($domain->name);
    }

    $register_price = Domain::registrar($domain)->pricing()->registrationPrice();
    Action::execute(new RegisterDomainName($user->organization, $domain, $register_price, 1, []));

    $domain->refresh();
    expect($domain->status)->toBe('active');
});

it('transfers a domain via namecheap', function () {
    if (is_null(config('domains.registrars.namecheap.url'))) {
        test()->markTestSkipped('Requires Namecheap registrar to be configured');
    }

    $support = new TestSupports;
    $support->seed();
    $user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($user);

    $this->get('/admin/service/domains/tlds/refresh');
    $check_domain = [];

    while (! array_key_exists('available', $check_domain) || $check_domain['available'] == true) {
        $domain_name = fake()->domainName();
        $tld_name = Domain::getTld($domain_name);
        $tld = Tld::where('name', $tld_name)->first();
        $domain = OrgDomain::factory()->create([
            'name' => $domain_name,
            'organization_id' => $user->organization->id,
            'type' => 'managed',
            'tld_id' => $tld->id,
            'status' => 'transferring',
        ]);
        $check_domain = Domain::registrar($domain->source)->check($domain->name);
    }

    $transfer_price = Domain::registrar($domain)->pricing()->transferPrice();
    Action::execute(new TransferDomainName($user->organization, $domain, 'epp_code', $transfer_price));

    expect($domain->status)->toBe('transferring');
});
