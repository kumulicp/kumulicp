<?php

use App\Integrations\Registrars\Namecheap\Interfaces\RegistrarInterface;
use App\OrgDomain;
use App\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();
    $organization = User::where('username', 'demo')->firstOrFail()->organization;

    config(['domains.registrars.namecheap.url' => 'https://api.sandbox.namecheap.test/xml.response']);

    $this->domain = OrgDomain::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'lolipops.com',
        'status' => 'registering',
        'country' => 'US',
        'country_phone_code' => '+1',
        'phone' => '604 555-0100',
    ]);
});

function namecheapXml(string $command, string $status, string $body): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<ApiResponse xmlns="http://api.namecheap.com/xml.response" Status="'.$status.'">'
        .($status === 'OK' ? '<Errors />' : '')
        .$body
        ."<RequestedCommand>$command</RequestedCommand>"
        .'<Server>TEST</Server></ApiResponse>';
}

function fakeNamecheap(string $create_response): void
{
    Http::fake(function (Request $request) use ($create_response) {
        if ($request['Command'] === 'namecheap.domains.create') {
            return Http::response($create_response);
        }

        // getinfo after a registration: not needed here, and the code copes with an error
        return Http::response(namecheapXml('namecheap.domains.getinfo', 'ERROR', '<Errors><Error Number="2019166">test</Error></Errors>'));
    });
}

it('throws Namecheap\'s error and leaves the domain unregistered when the registration is refused', function () {
    // The answer from the sandbox for a phone number it did not accept
    fakeNamecheap(namecheapXml(
        'namecheap.domains.create',
        'ERROR',
        '<Errors><Error Number="2011182">Parameter RegistrantPhone is Invalid</Error></Errors>',
    ));

    expect(fn () => (new RegistrarInterface)->register($this->domain, 1))
        ->toThrow(Exception::class, 'Parameter RegistrantPhone is Invalid');

    $this->domain->refresh();
    expect($this->domain->status)->toBe('registering')
        ->and($this->domain->registered)->toBeNull()
        ->and($this->domain->domain_id)->toBeNull();
});

it('throws when Namecheap answers without registering the domain', function () {
    fakeNamecheap(namecheapXml(
        'namecheap.domains.create',
        'OK',
        '<CommandResponse Type="namecheap.domains.create"><DomainCreateResult Domain="lolipops.com" Registered="false" ChargedAmount="0" DomainID="0" OrderID="0" TransactionID="0" WhoisguardEnable="false" NonRealTimeDomain="false" /></CommandResponse>',
    ));

    expect(fn () => (new RegistrarInterface)->register($this->domain, 1))
        ->toThrow(Exception::class, 'lolipops.com could not be registered');

    expect($this->domain->refresh()->status)->toBe('registering');
});

it('sends the contact phone in Namecheap\'s format', function () {
    $this->domain->forceFill(['country_phone_code' => '+1', 'phone' => '(604) 555-0100'])->save();
    fakeNamecheap(namecheapXml('namecheap.domains.create', 'ERROR', '<Errors><Error Number="2011182">Parameter RegistrantPhone is Invalid</Error></Errors>'));

    expect(fn () => (new RegistrarInterface)->register($this->domain, 1))->toThrow(Exception::class);

    Http::assertSent(fn (Request $request) => $request['Command'] === 'namecheap.domains.create'
        && collect(['RegistrantPhone', 'TechPhone', 'AdminPhone', 'AuxBillingPhone'])
            ->every(fn ($field) => $request[$field] === '+1.6045550100'));
});

it('marks the domain registered when Namecheap registers it', function () {
    fakeNamecheap(namecheapXml(
        'namecheap.domains.create',
        'OK',
        '<CommandResponse Type="namecheap.domains.create"><DomainCreateResult Domain="lolipops.com" Registered="true" ChargedAmount="15.98" DomainID="7001" OrderID="8001" TransactionID="9001" WhoisguardEnable="true" NonRealTimeDomain="false" /></CommandResponse>',
    ));

    (new RegistrarInterface)->register($this->domain, 1);

    $this->domain->refresh();
    expect($this->domain->status)->toBe('active')
        ->and($this->domain->registered)->toBeTrue()
        ->and((string) $this->domain->domain_id)->toBe('7001')
        ->and((float) $this->domain->charged_amount)->toBe(15.98);
});

it('does not fail a non-real-time registration that Namecheap has accepted but not finished', function () {
    fakeNamecheap(namecheapXml(
        'namecheap.domains.create',
        'OK',
        '<CommandResponse Type="namecheap.domains.create"><DomainCreateResult Domain="lolipops.com" Registered="false" ChargedAmount="15.98" DomainID="0" OrderID="8001" TransactionID="9001" WhoisguardEnable="false" NonRealTimeDomain="true" /></CommandResponse>',
    ));

    (new RegistrarInterface)->register($this->domain, 1);

    expect($this->domain->refresh()->status)->toBe('registering');
});
