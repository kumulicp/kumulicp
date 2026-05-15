<?php

use App\OrgDomain;
use App\OrgSubdomain;
use App\User;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TestSupports;

it('adds and removes a domain', function () {
    $support = new TestSupports;
    $support->seed();
    $support->createBase2Plan();
    $user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($user);

    $support->setSubscription($user->organization, $support->base_2);

    $domain = $this->post('/settings/domains/connect', [
        'domain_name' => 'example.com',
    ]);

    $domain->assertSessionHasNoErrors();

    expect(OrgDomain::where('name', 'example.com')->count())->toBe(1);
    expect(OrgSubdomain::where('name', 'example.com')->where('host', '@')->count())->toBe(1);

    $this->post('/settings/domains/example.com/renew')->assertForbidden();
    $this->post('/settings/domains/example.com/reactivate')->assertForbidden();
    $this->post('/settings/domains/example.com/request_transfer')->assertForbidden();
    $this->post('/settings/domains/example.com/self_manage')->assertForbidden();
    $this->post('/settings/domains/example.com/transfer_in')->assertForbidden();

    $this->followingRedirects();
    $this->post('/settings/domains/example.com/remove')->assertSuccessful();

    Artisan::call('schedule:run');

    expect(OrgDomain::where('name', 'example.com')->count())->toBe(0);
    expect(OrgSubdomain::where('name', 'example.com')->where('host', '@')->count())->toBe(0);
});
