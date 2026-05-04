<?php

namespace Tests\Feature\Services;

use App\Support\Facades\Domain;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class DomainServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_service()
    {
        if (! config('domains.registrars.namecheap.url')) {
            return;
        }
        $support = new TestSupports;
        $support->seed();

        $user = User::find(1);
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
    }
}
