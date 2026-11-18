<?php

use App\Integrations\Registrars\Namecheap\Helpers\DnsHosts;
use App\OrgDomain;
use App\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();
    $this->organization = User::where('username', 'demo')->firstOrFail()->organization;

    DB::table('servers')->insert([[
        'id' => 2,
        'app_instance_id' => 0,
        'name' => 'Mail',
        'address' => 'mail.example.net',
        'host' => 'mail.example.net',
        'api_key' => 'api_key',
        'api_secret' => 'api_secret',
        'default_web_server' => 0,
        'default_email_server' => 1,
        'default_database_server' => 0,
        'internal_address' => 'localhost',
        'type' => 'email',
        'interface' => 'ldap',
        'settings' => '{}',
        'ip' => '203.0.113.10',
        'status' => 'active',
    ]]);
    DB::table('org_servers')->insert([['id' => 2, 'organization_id' => $this->organization->id, 'server_id' => 2]]);
});

function namecheapRecords(array $hosts): array
{
    $records = [];

    for ($n = 1; isset($hosts["HostName$n"]); $n++) {
        $records[] = [$hosts["HostName$n"], $hosts["RecordType$n"], $hosts["Address$n"]];
    }

    return $records;
}

it('lists the email records once when they are asked for again, as setHosts does', function () {
    $domain = OrgDomain::factory()->create([
        'organization_id' => $this->organization->id,
        'name' => 'example.com',
        'status' => 'active',
        'email_enabled' => true,
        'email_status' => 'active',
        'email_server_id' => 2,
    ]);

    // The constructor adds the records; DomainsDns::setHosts() adds them again when the organization
    // already has an active email domain. That second call used to throw on ->all() of an array.
    $hosts = new DnsHosts($domain);
    $hosts->addEmailRecords();
    $hosts->with_email = true;

    $converted = $hosts->convertToNamecheap();
    $records = namecheapRecords($converted);

    expect($converted['EmailType'])->toBe('MX');
    expect($records)->toHaveCount(4);
    expect(array_column($records, 1))->toBe(['MX', 'TXT', 'TXT', 'TXT']);
    expect($records[0])->toBe(['@', 'MX', 'mail.example.net']);
    expect($records[1][2])->toBe('v=spf1 ip4:203.0.113.10 ~all');
    expect(array_column($records, 0))->toBe(['@', '@', 'mail._domainkey', '_dmarc']);
});

it('adds no email records or EmailType to a domain without an email server', function () {
    $domain = OrgDomain::factory()->create([
        'organization_id' => $this->organization->id,
        'name' => 'example.org',
        'status' => 'active',
        'email_server_id' => null,
    ]);

    $hosts = new DnsHosts($domain);
    $hosts->addEmailRecords();

    expect($hosts->convertToNamecheap())->not->toHaveKey('EmailType');
    expect(namecheapRecords($hosts->convertToNamecheap()))->toBe([]);
});

it('reports whether there is anything to publish', function () {
    $without_email = OrgDomain::factory()->create([
        'organization_id' => $this->organization->id,
        'name' => 'nothing.example',
        'status' => 'active',
        'email_server_id' => null,
    ]);
    // What registration creates: the wildcard row, with no value and no app
    $without_email->subdomains()->forceCreate([
        'organization_id' => $this->organization->id,
        'host' => '*',
        'name' => 'nothing.example',
        'type' => 'A',
        'ttl' => 1800,
        'status' => 'active',
    ]);
    $with_email = OrgDomain::factory()->create([
        'organization_id' => $this->organization->id,
        'name' => 'mail.example',
        'status' => 'active',
        'email_server_id' => 2,
    ]);
    $with_record = OrgDomain::factory()->create([
        'organization_id' => $this->organization->id,
        'name' => 'record.example',
        'status' => 'active',
        'email_server_id' => null,
    ]);
    $with_record->subdomains()->forceCreate([
        'organization_id' => $this->organization->id,
        'host' => 'www',
        'name' => 'www.record.example',
        'type' => 'CNAME',
        'value' => 'target.example.',
        'ttl' => 1800,
        'status' => 'active',
    ]);

    expect((new DnsHosts($without_email))->hasRecords())->toBeFalse()
        ->and((new DnsHosts($with_email))->hasRecords())->toBeTrue()
        ->and((new DnsHosts($with_record))->hasRecords())->toBeTrue();
});
