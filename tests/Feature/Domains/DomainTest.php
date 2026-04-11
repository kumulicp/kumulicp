<?php

namespace Tests\Feature\Domains;

use App\OrgDomain;
use App\OrgSubdomain;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TestSupports;
use Tests\TestCase;

class DomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_domain()
    {
        // Initial setup
        $support = new TestSupports;
        $support->seed();
        $user = User::find(1);
        $this->actingAs($user);

        $domain = $this->post('/settings/domains/connect', [
            'domain_name' => 'example.com',
        ]);

        $domain->assertSessionHasNoErrors();

        $org_domain = OrgDomain::where('name', 'example.com')->count();
        $subdomain = OrgSubdomain::where('name', 'example.com')->where('host', '@')->count();

        $this->assertEquals(1, $org_domain);
        $this->assertEquals(1, $subdomain);

        $domain = $this->post('/settings/domains/example.com/renew');
        $domain->assertForbidden();
        $domain = $this->post('/settings/domains/example.com/reactivate');
        $domain->assertForbidden();
        $domain = $this->post('/settings/domains/example.com/request_transfer');
        $domain->assertForbidden();
        $domain = $this->post('/settings/domains/example.com/self_manage');
        $domain->assertForbidden();
        $domain = $this->post('/settings/domains/example.com/transfer_in');
        $domain->assertForbidden();
        $domain = $this->post('/settings/domains/example.com/enable_email');
        $domain->assertForbidden();
        $this->followingRedirects();
        $domain = $this->post('/settings/domains/example.com/remove');
        $domain->assertSuccessful();

        Artisan::call('schedule:run');

        $org_domain = OrgDomain::where('name', 'example.com')->count();
        $subdomain = OrgSubdomain::where('name', 'example.com')->where('host', '@')->count();

        $this->assertEquals(0, $org_domain);
        $this->assertEquals(0, $subdomain);
    }

    /*public function test_edit_connected_domain()
    {
        // Initial setup
        $support = new TestSupports;
        $support->seed();
        $user = User::find(1);
        $this->actingAs($user);

        $domain->assertSessionHasNoErrors();

        $org_domain = OrgDomain::where('name', 'example.com')->count();
        $subdomain = OrgSubdomain::where('name', 'example.com')->where('host', '@')->count();

        $this->assertEquals(1, $org_domain);
        $this->assertEquals(1, $subdomain);
    }*/
}
