<?php

use App\Actions\Domains\RegisterDomainName;
use App\OrgDomain;
use App\Support\Facades\Action;
use App\Task;
use App\Tld;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();
    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($this->user);

    setupRegistrarDriver('fake');
    setupBillingDriver('fake');

    $tld = Tld::firstOrCreate(['name' => 'com'], [
        'default_driver' => 'fake',
        'is_api_registerable' => true,
        'standard_price' => 9.99,
        'min_register_years' => 1,
        'max_register_years' => 10,
    ]);

    $this->domain = OrgDomain::factory()->create([
        'organization_id' => $this->user->organization->id,
        'name' => 'newly-registered.com',
        'type' => 'managed',
        'tld_id' => $tld->id,
        'source' => 'fake',
        'status' => 'registering',
    ]);
});

function runRegistration(): void
{
    Action::execute(new RegisterDomainName(test()->user->organization, test()->domain, 15.98, 1, []));

    RegisterDomainName::run(Task::where('action_slug', 'register_domain_name')->firstOrFail());
}

it('invoices a registration but does not touch DNS when there is nothing to publish', function () {
    // The wildcard row registration creates: no value and no app, so nothing to publish
    $this->domain->subdomains()->forceCreate([
        'organization_id' => $this->user->organization->id,
        'host' => '*',
        'name' => $this->domain->name,
        'type' => 'A',
        'ttl' => 1800,
        'status' => 'active',
    ]);

    runRegistration();

    expect(Task::where('action_slug', 'invoice_organization')->exists())->toBeTrue()
        ->and(Task::where('action_slug', 'update_dns_records')->exists())->toBeFalse();
});

it('updates DNS after a registration when there are records to publish', function () {
    $this->domain->subdomains()->forceCreate([
        'organization_id' => $this->user->organization->id,
        'host' => 'www',
        'name' => 'www.'.$this->domain->name,
        'type' => 'CNAME',
        'value' => 'target.example.',
        'ttl' => 1800,
        'status' => 'active',
    ]);

    runRegistration();

    expect(Task::where('action_slug', 'update_dns_records')->exists())->toBeTrue();
});
