<?php

namespace Tests\Feature\Domains;

use App\Actions\Domains\RegisterDomainName;
use App\Actions\Domains\TransferDomainName;
use App\OrgDomain;
use App\Support\Facades\Action;
use App\Support\Facades\Domain;
use App\Tld;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class NamecheapTest extends TestCase
{
    use RefreshDatabase;

    public function test_namecheap_domain_register()
    {
        if (! is_null(config('domain.registrars.namecheap.url'))) {
            // Initial setup
            $support = new TestSupports;
            $support->seed();
            $user = User::find(1);
            $this->actingAs($user);

            $tld_refresh = $this->get('/admin/service/domains/tlds/refresh');
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

            $domain_task = Action::execute(new RegisterDomainName($user->organization, $domain, $register_price, 1, []));

            $domain->refresh();
            $this->assertEquals('active', $domain->status);
        }
    }

    public function test_namecheap_domain_transfer()
    {
        if (! is_null(config('domains.registrars.namecheap.url'))) {
            // Initial setup
            $support = new TestSupports;
            $support->seed();
            $user = User::find(1);
            $this->actingAs($user);

            $tld_refresh = $this->get('/admin/service/domains/tlds/refresh');
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

            $tranfer_price = Domain::registrar($domain)->pricing()->transferPrice();

            $domain_task = Action::execute(new TransferDomainName($user->organization, $domain, 'epp_code', $tranfer_price));

            $this->assertEquals('transferring', $domain->status);
        }
    }
}
