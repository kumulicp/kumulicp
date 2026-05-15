<?php

use App\Support\Facades\Domain;
use App\User;
use Tests\Support\TestSupports;

it('checks domain availability via namecheap service', function () {
    if (! config('domains.registrars.namecheap.url')) {
        test()->markTestSkipped('Requires Namecheap registrar to be configured');
    }

    $support = new TestSupports;
    $support->seed();

    $user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($user);
    $this->followingRedirects();
    $tld_refresh = $this->get('/admin/service/domains/tlds/refresh');
    $domain_name = fake()->domainName();
    $check_domain = Domain::registrar('namecheap')->check($domain_name);

    while (! array_key_exists('available', $check_domain) || $check_domain['available'] == false) {
        $domain_name = fake()->domainName();
        $check_domain = Domain::registrar('namecheap')->check($domain_name);
    }

    $tld_refresh->assertStatus(200);
    $domain_availability = $this->post('/settings/domains/availability', [
        'domain_name' => $domain_name,
    ]);
    $domain_availability->assertSessionHasNoErrors();
});
