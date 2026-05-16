<?php

namespace Tests\Support\Registrars;

use App\Contracts\Registrar\RegistrarContract;
use App\OrgDomain;
use App\Tld;

/**
 * In-memory stand-in for RegistrarInterface (Namecheap).
 *
 * check() behaviour is driven by the domain name so it works in both
 * feature tests (same process) and browser tests (shared in-process server):
 *
 *   - name contains "unavailable"  → available: false
 *   - name contains "premium"      → available: true, is_premium_name: true
 *   - anything else                → available: true, standard domain
 */
class FakeRegistrar implements RegistrarContract
{
    public function register(OrgDomain $org_domain, int $years, $extended_attributes = null): object
    {
        $org_domain->domain_id = 'fake-'.uniqid();
        $org_domain->charged_amount = 9.99 * $years;
        $org_domain->whois_guard_enabled = false;
        $org_domain->non_real_time_domain = false;
        $org_domain->order_id = 'fake-order-'.uniqid();
        $org_domain->transaction_id = 'fake-txn-'.uniqid();
        $org_domain->registered = true;
        $org_domain->registered_at = now()->toDateString();
        $org_domain->expires_at = now()->addYears($years)->toDateString();
        $org_domain->status = 'active';
        $org_domain->save();

        return $this->select($org_domain);
    }

    public function select(OrgDomain $domain): object
    {
        return new FakeDomainInterface($domain);
    }

    public function list(): array
    {
        return OrgDomain::all()->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'user' => '',
            'created' => $d->created_at,
            'expires' => '',
            'is_expired' => false,
            'is_locked' => false,
            'auto_renew' => false,
            'whois_guard' => false,
            'is_premium' => false,
            'is_our_dns' => false,
        ])->toArray();
    }

    public function info(string $domain_name): array
    {
        $domain = OrgDomain::where('name', $domain_name)->first();

        return [
            'status' => $domain?->status ?? 'active',
            'id' => $domain?->id ?? 0,
            'domain_name' => $domain_name,
            'owner_name' => 'Fake Owner',
            'is_owner' => true,
            'is_premium' => false,
            'created_date' => now()->toDateString(),
            'expired_date' => now()->addYear()->toDateString(),
            'whois_guard' => [
                'enabled' => false,
                'id' => null,
                'expired_date' => null,
                'whois_guard_email' => null,
                'forwarded_to' => null,
                'last_auto_email_change_date' => null,
                'auto_email_change_frequency_days' => null,
            ],
        ];
    }

    public function check(string $domain_name): array
    {
        if (str_contains($domain_name, 'unavailable')) {
            return [
                'available' => false,
                'is_premium_name' => false,
                'ican_fee' => 0.18,
                'premium_registration_price' => 0.0,
                'premium_renewal_price' => 0.0,
                'premium_restore_price' => 0.0,
                'premium_transfer_price' => 0.0,
            ];
        }

        if (str_contains($domain_name, 'premium')) {
            return [
                'available' => true,
                'is_premium_name' => true,
                'ican_fee' => 0.18,
                'premium_registration_price' => 99.99,
                'premium_renewal_price' => 89.99,
                'premium_restore_price' => 49.99,
                'premium_transfer_price' => 79.99,
            ];
        }

        return [
            'available' => true,
            'is_premium_name' => false,
            'ican_fee' => 0.18,
            'premium_registration_price' => 0.0,
            'premium_renewal_price' => 0.0,
            'premium_restore_price' => 0.0,
            'premium_transfer_price' => 0.0,
        ];
    }

    public function pricing(Tld $tld, $domain_name): object
    {
        return new FakePricingInterface(str_contains($domain_name, 'premium'));
    }

    public function tldList(): array
    {
        return [
            ['name' => 'com', 'min_registration' => 1, 'max_registration' => 10],
            ['name' => 'org', 'min_registration' => 1, 'max_registration' => 10],
            ['name' => 'net', 'min_registration' => 1, 'max_registration' => 10],
        ];
    }
}
