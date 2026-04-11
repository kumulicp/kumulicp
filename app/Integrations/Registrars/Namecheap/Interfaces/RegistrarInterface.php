<?php

namespace App\Integrations\Registrars\Namecheap\Interfaces;

use App\Contracts\Registrar\RegistrarContract;
use App\Exceptions\DomainRegistrationException;
use App\Integrations\Registrars\Namecheap\API\Domains;
use App\OrgDomain;
use App\Support\Facades\Organization;
use App\Tld;
use Illuminate\Support\Arr;

class RegistrarInterface implements RegistrarContract
{
    public function register(OrgDomain $org_domain, $years, $extended_attributes = null)
    {
        $domain = new Domains($org_domain->organization);

        $domain_response = $domain->create($org_domain, $years, $extended_attributes);

        if ($domain_response && Arr::get($domain_response, 'Registered', 'false') == 'true') {
            $org_domain->domain_id = $domain_response['DomainID'];
            $org_domain->name = $domain_response['Domain'];
            $org_domain->charged_amount = $domain_response['ChargedAmount'];
            $org_domain->whois_guard_enabled = $domain_response['WhoisguardEnable'];
            $org_domain->non_real_time_domain = $domain_response['NonRealTimeDomain'];
            $org_domain->order_id = $domain_response['OrderID'];
            $org_domain->transaction_id = $domain_response['TransactionID'];
            $org_domain->registered = true;
            $org_domain->status = 'active';
            $org_domain->save();

            $domain_info = $domain->info($org_domain->name);

            if ($domain_info && Arr::has($domain_info, 'CreatedDate')) {
                $org_domain->registered_at = $domain_info['CreatedDate'];
                $org_domain->expires_at = $domain_info['ExpiredDate'];
                $org_domain->save();
            }
        }

        return $this->select($org_domain);
    }

    public function select(OrgDomain $domain): object
    {
        return new DomainInterface($domain);
    }

    public function list(): array
    {
        $organization = auth()->user()->organization;

        $error = false;
        $domain_list = [];
        $domains_error = '';

        $domains = new Domains($organization);
        $domain_response = $domains->list();

        if ($domains->hasError()) {
            $domains_error = $domains->error();
            $error = true;
        }

        foreach ($domain_response as $domain) {
            $domain_list[] = [
                'id' => $domain['ID'],
                'name' => $domain['Name'],
                'user' => $domain['User'],
                'created' => $domain['Created'],
                'expires' => $domain['Expires'],
                'is_expired' => $domain['IsExpired'],
                'is_locked' => $domain['IsLocked'],
                'auto_renew' => $domain['AutoRenew'],
                'whois_guard' => $domain['WhoisGuard'],
                'is_premium' => $domain['IsPremium'],
                'is_our_dns' => $domain['IsOurDNS'],
            ];
        }

        return $domain_list;
    }

    public function info(string $domain_name): array
    {
        $domains = new Domains(Organization::account());
        $domain = $domains->info($domain_name);

        return [
            'status' => $domain['Status'],
            'id' => $domain['ID'],
            'domain_name' => $domain['DomainName'],
            'owner_name' => $domain['OwnerName'],
            'is_owner' => $domain['IsOwner'],
            'is_premium' => $domain['IsPremium'],
            'created_date' => $domain['CreatedDate'],
            'expired_date' => $domain['ExpiredDate'],
            'whois_guard' => [
                'enabled' => $domain['Whoisguard']['Enabled'],
                'id' => $domain['Whoisguard']['ID'],
                'expired_date' => $domain['Whoisguard']['ExpiredDate'],
                'whois_guard_email' => $domain['Whoisguard']['WhoisGuardEmail'],
                'forwarded_to' => $domain['Whoisguard']['ForwardedTo'],
                'last_auto_email_change_date' => $domain['Whoisguard']['LastAutoEmailChangeDate'],
                'auto_email_change_frequency_days' => $domain['Whoisguard']['AutoEmailChangeFrequencyDays'],
            ],
        ];
    }

    public function check(string $domain_name): array
    {
        $domains = new Domains(Organization::account());
        $domain = $domains->check($domain_name);

        if ($domains->hasError()) {
            throw new DomainRegistrationException($domains->error());
        }

        return [
            'available' => $domain['Available'],
            'is_premium_name' => $domain['IsPremiumName'],
            'ican_fee' => $domain['IcannFee'],
            'premium_registration_price' => $domain['PremiumRegistrationPrice'],
            'premium_renewal_price' => $domain['PremiumRenewalPrice'],
            'premium_restore_price' => $domain['PremiumRestorePrice'],
            'premium_transfer_price' => $domain['PremiumTransferPrice'],
        ];
    }

    public function pricing(Tld $tld, $domain_name): object
    {
        return new PricingInterface($tld, $domain_name);
    }

    public function tldList(): array
    {
        $domains = new Domains(Organization::account());

        return $domains->tldList();
    }
}
