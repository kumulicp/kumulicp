<?php

namespace App\Integrations\Registrars\Namecheap\API;

use App\Integrations\Registrars\Namecheap\Namecheap;
use App\OrgDomain;
use App\Support\Facades\Domain;
use Carbon\Carbon;

class Domains extends Namecheap
{
    public function list($list_type = 'ALL', $search_term = '', $page = 1, $page_size = '100', $sort_by = 'NAME')
    {
        $this->command = 'namecheap.domains.getList';
        $this->parameters = [
            'ListType' => $list_type,
            'Page' => $page,
            'PageSize' => $page_size,
            'SortBy' => $sort_by,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());

        $domains_response = $this->response_content()->DomainGetListResult;

        foreach ($domains_response->Domain as $domain) {
            $attributes = $domain->attributes();

            $domains[] = [
                'ID' => (int) $attributes['ID'],
                'Name' => (string) $attributes['Name'],
                'User' => (string) $attributes['User'],
                'Created' => (string) $attributes['Created'],
                'Expires' => (string) $attributes['Expires'],
                'IsExpired' => (string) $attributes['IsExpired'] == 'true',
                'IsLocked' => (string) $attributes['IsLocked'] == 'true',
                'AutoRenew' => (string) $attributes['AutoRenew'] == 'true',
                'WhoisGuard' => (string) $attributes['WhoisGuard'] == 'ENABLED',
                'IsPremium' => (string) $attributes['IsPremium'] == 'true',
                'IsOurDNS' => (string) $attributes['IsOurDNS'] == 'true',
            ];
        }

        return $domains;
    }

    public function contacts($domain_name)
    {
        $this->command = 'namecheap.domains.contact';
        $this->parameters = [
            'DomainName' => $domain_name,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function create(OrgDomain $domain, $years, $extended_attributes = '')
    {
        $organization = $domain->organization;

        if ($domain->status != 'registering') {
            $this->error = "Domain isn't registered";

            return;
        }
        if ($extended_attributes === false) {
            $this->error = __('organization.domain.denied.extended_attributes');

            return;
        }

        $whois_guard = Domain::getTld($domain->name) !== 'ca' ? 'yes' : 'no';

        $phone = str_replace(' ', '', $domain->country_phone_code).'.'.str_replace('-', '', $domain->phone);

        $this->command = 'namecheap.domains.create';
        $this->parameters = [
            'DomainName' => $domain->name,
            'Years' => (int) $years,
            'RegistrantOrganizationName' => $organization->name,
            'RegistrantFirstName' => $domain->first_name,
            'RegistrantLastName' => $domain->last_name,
            'RegistrantAddress1' => $domain->address_1,
            'RegistrantAddress2' => $domain->address_2,
            'RegistrantCity' => $domain->city,
            'RegistrantStateProvince' => $domain->state_province,
            'RegistrantPostalCode' => $domain->postal_code,
            'RegistrantCountry' => $domain->country,
            'RegistrantPhone' => $phone,
            'RegistrantEmailAddress' => $domain->email_address,
            'TechOrganizationName' => $organization->name,
            'TechFirstName' => $domain->first_name,
            'TechLastName' => $domain->last_name,
            'TechAddress1' => $domain->address_1,
            'TechAddress2' => $domain->address_2,
            'TechCity' => $domain->city,
            'TechStateProvince' => $domain->state_province,
            'TechPostalCode' => $domain->postal_code,
            'TechCountry' => $domain->country,
            'TechPhone' => $phone,
            'TechEmailAddress' => $domain->email_address,
            'AdminOrganizationName' => $organization->name,
            'AdminFirstName' => $domain->first_name,
            'AdminLastName' => $domain->last_name,
            'AdminAddress1' => $domain->address_1,
            'AdminAddress2' => $domain->address_2,
            'AdminCity' => $domain->city,
            'AdminStateProvince' => $domain->state_province,
            'AdminPostalCode' => $domain->postal_code,
            'AdminCountry' => $domain->country,
            'AdminPhone' => $phone,
            'AdminEmailAddress' => $domain->email_address,
            'AuxBillingOrganizationName' => $organization->name,
            'AuxBillingFirstName' => $domain->first_name,
            'AuxBillingLastName' => $domain->last_name,
            'AuxBillingAddress1' => $domain->address_1,
            'AuxBillingAddress2' => $domain->address_2,
            'AuxBillingCity' => $domain->city,
            'AuxBillingStateProvince' => $domain->state_province,
            'AuxBillingPostalCode' => $domain->postal_code,
            'AuxBillingCountry' => $domain->country,
            'AuxBillingPhone' => $phone,
            'AuxBillingEmailAddress' => $domain->email_address,
            'AddFreeWhoisguard' => $whois_guard,
            'WGEnabled' => $whois_guard,
            'IsPremiumDomain' => $domain->is_premium ? 'true' : 'false',
            'PremiumPrice' => $domain->premium_registration_price,
        ];

        if (is_array($extended_attributes)) {
            $this->parameters = array_merge($this->parameters, $extended_attributes);
        }

        $this->form()->post($this->basePath(), $this->postParameters());

        if (! $this->hasError()) {

            $attributes = $this->response_content()->DomainCreateResult->attributes();

            return [
                'Domain' => (string) $attributes['Domain'],
                'Registered' => (string) $attributes['Registered'] == 'true',
                'ChargedAmount' => (float) $attributes['ChargedAmount'],
                'DomainID' => (int) $attributes['DomainID'],
                'OrderID' => (int) $attributes['OrderID'],
                'TransactionID' => (int) $attributes['TransactionID'],
                'WhoisguardEnable' => (string) $attributes['WhoisguardEnable'] == 'true',
                'NonRealTimeDomain' => (string) $attributes['NonRealTimeDomain'] == 'true',
            ];
        }

        return null;
    }

    public function tldList()
    {
        $this->command = 'namecheap.domains.getTldList';
        $this->parameters = [

        ];

        $this->form()->post($this->basePath(), $this->postParameters());

        foreach ($this->response_content()->Tlds->Tld as $tld) {
            $attributes = $tld->attributes();

            $tlds[] = [
                'name' => (string) $attributes['Name'],
                'non_real_time' => (string) $attributes['NonRealTime'] == 'true',
                'min_register_years' => (int) $attributes['MinRegisterYears'],
                'max_register_years' => (int) $attributes['MaxRegisterYears'],
                'min_renew_years' => (int) $attributes['MinRenewYears'],
                'max_renew_years' => (int) $attributes['MaxRenewYears'],
                'renewal_min_days' => (int) $attributes['RenewalMinDays'],
                'renewal_max_days' => (int) $attributes['RenewalMaxDays'],
                'reactivate_max_days' => (int) $attributes['ReactivateMaxDays'],
                'min_transfer_years' => (int) $attributes['MinTransferYears'],
                'max_transfer_years' => (int) $attributes['MaxTransferYears'],
                'is_api_registerable' => (string) $attributes['IsApiRenewable'] == 'true',
                'is_api_renewable' => (string) $attributes['IsApiRenewable'] == 'true',
                'is_api_transferable' => (string) $attributes['IsApiTransferable'] == 'true',
                'is_epp_required' => (string) $attributes['IsEppRequired'] == 'true',
                'is_disable_mod_contact' => (string) $attributes['IsDisableModContact'] == 'true',
                'is_disable_wgallot' => (string) $attributes['IsDisableWGAllot'] == 'true',
                'is_include_in_extended_search_only' => (string) $attributes['IsIncludeInExtendedSearchOnly'] == 'true',
                'sequence_number' => (int) $attributes['SequenceNumber'],
                'type' => (string) $attributes['Type'],
                'sub_type' => (string) $attributes['SubType'],
                'is_supports_idn' => (string) $attributes['IsSupportsIDN'] == 'true',
                'category' => (string) $attributes['Category'],
                'supports_registrar_lock' => (string) $attributes['SupportsRegistrarLock'] == 'true',
                'add_grace_period_days' => (int) $attributes['AddGracePeriodDays'],
                'whois_verification' => (string) $attributes['WhoisVerification'] == 'true',
                'provider_api_delete' => (string) $attributes['ProviderApiDelete'] == 'true',
                'tld_state' => (string) $attributes['TldState'],
                'search_group' => (string) $attributes['SearchGroup'],
                'registry' => (string) $attributes['Registry'],
            ];
        }

        return $tlds;
    }

    public function setContacts($domain_name)
    {
        $this->command = 'namecheap.domains.SetContacts';
        $this->parameters = [
            'DomainName' => $domain_name,
            'Years' => $years,
            'RegistrantFirstName' => $registrant_first_name,
            'RegistrantLastName' => $registrant_last_name,
            'RegistrantAddress1' => $registrant_address_1,
            'RegistrantCity' => $registrant_city,
            'RegistrantStateProvince' => $registrant_state_province,
            'RegistrantPostalCode' => $registrant_postal_code,
            'RegistrantCountry' => $registrant_country,
            'RegistrantPhone' => $registrant_phone,
            'RegistrantEmailAddress' => $registrant_email_address,
            'TechFirstName' => $tech_first_name,
            'TechLastName' => $tech_last_name,
            'TechAddress1' => $tech_address_1,
            'TechCity' => $tech_city,
            'TechStateProvince' => $tech_province,
            'TechPostalCode' => $tech_postal_code,
            'TechCountry' => $tech_country,
            'TechPhone' => $tech_phone,
            'TechEmailAddress' => $tech_email_address,
            'AdminFirstName' => $admin_first_name,
            'AdminLastName' => $admin_last_name,
            'AdminAddress1' => $admin_address_1,
            'AdminCity' => $admin_city,
            'AdminStateProvince' => $admin_state_province,
            'AdminPostalCode' => $admin_postal_code,
            'AdminCountry' => $admin_country,
            'AdminPhone' => $admin_phone,
            'AdminEmailAddress' => $admin_email_address,
            'AuxBillingFirstName' => $aux_first_name,
            'AuxBillingLastName' => $aux_last_name,
            'AuxBillingAddress1' => $aux_address1,
            'AuxBillingCity' => $aux_city,
            'AuxBillingStateProvince' => $aux_province,
            'AuxBillingPostalCode' => $aux_postal_code,
            'AuxBillingCountry' => $aux_country,
            'AuxBillingPhone' => $aux_phone,
            'AuxBillingEmailAddress' => $aux_email_address,
            'Extended attributes' => $extended_attributes,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function check($domain_list)
    {
        $this->command = 'namecheap.domains.check';
        $this->parameters = [
            'DomainList' => $domain_list,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());

        if (! $this->hasError()) {

            $attributes = $this->response_content()->DomainCheckResult->attributes();

            return [
                'Domain' => (string) $attributes['Domain'],
                'Available' => (string) $attributes['Available'] == 'true',
                'ErrorNo' => (int) $attributes['ErrorNo'],
                'Description' => (string) $attributes['Description'],
                'IsPremiumName' => ((string) $attributes['IsPremiumName'] == 'true'),
                'PremiumRegistrationPrice' => (float) $attributes['PremiumRegistrationPrice'],
                'PremiumRenewalPrice' => (float) $attributes['PremiumRenewalPrice'],
                'PremiumRestorePrice' => (float) $attributes['PremiumRestorePrice'],
                'PremiumTransferPrice' => (float) $attributes['PremiumTransferPrice'],
                'IcannFee' => (float) $attributes['IcannFee'],
                'EapFee' => (float) $attributes['EapFee'],
            ];
        }

        return null;
    }

    public function reactivate($domain_name)
    {
        $this->command = 'namecheap.domains.reactivate';
        $this->parameters = [
            'DomainName' => $domain_name,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());

        if (! $this->hasError()) {
            $attributes = $this->response_content()->DomainReactivateResult->attributes();

            return [
                'Domain' => (string) $attributes['Domain'],
                'IsSuccess' => (string) $attributes['IsSuccess'] == 'true',
                'ChargedAmount' => (float) $attributes['ChargedAmount'],
                'OrderID' => (int) $attributes['OrderID'],
                'TransactionID' => (int) $attributes['TransactionID'],
            ];
        }
    }

    public function renew(OrgDomain $domain, $years)
    {
        $this->command = 'namecheap.domains.renew';
        $this->parameters = [
            'DomainName' => $domain->name,
            'Years' => (int) $years,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());

        if (! $this->hasError()) {

            $attributes = $this->response_content()->DomainRenewResult->attributes();

            $expired_date = Carbon::createFromFormat('m/d/Y H:i:s A', $this->response_content()->DomainRenewResult->DomainDetails->ExpiredDate)->toDateTimeString();

            return [
                'DomainName' => (string) $attributes['DomainName'],
                'DomainID' => (int) $attributes['DomainID'],
                'Renew' => (string) $attributes['Renew'] == 'true',
                'OrderID' => (int) $attributes['OrderID'],
                'TransactionID' => (int) $attributes['TransactionID'],
                'ChargedAmount' => (float) $attributes['ChargedAmount'],
                'ExpiredDate' => $expired_date,
                'NumYears' => (int) $this->response_content()->DomainRenewResult->DomainDetails->NumYears,
            ];
        }

        return null;
    }

    public function registrarLock($domain_name)
    {
        $this->command = 'namecheap.domains.getRegistrarLock';
        $this->parameters = [
            'DomainName' => $domain_name,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function setRegistrarLock($domain_name, $lock)
    {
        $this->command = 'namecheap.domains.setRegistrarLock';
        $this->parameters = [
            'DomainName' => $domain_name,
            'LockAction' => $lock,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());

        if (! $this->hasError()) {
            $attributes = $this->response_content()->DomainSetRegistrarLockResult->attributes();

            return [
                'Domain' => (string) $attributes['Domain'],
                'IsSuccess' => (string) $attributes['IsSuccess'] == 'true',
            ];
        }

        return null;
    }

    public function info($domain_name)
    {
        $this->command = 'namecheap.domains.getinfo';
        $this->parameters = [
            'DomainName' => $domain_name,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());

        if (! $this->hasError()) {
            $response = $this->response_content()->DomainGetInfoResult;
            $attributes = $response->attributes();

            $created_date = Carbon::createFromFormat('m/d/Y H:i:s', $response->DomainDetails->CreatedDate.' 00:00:00')->toDateTimeString();
            $expired_date = Carbon::createFromFormat('m/d/Y H:i:s', $response->DomainDetails->ExpiredDate.' 00:00:00')->toDateTimeString();

            return [
                'Status' => (string) $attributes['Status'],
                'ID' => (string) $attributes['ID'],
                'DomainName' => (string) $attributes['DomainName'],
                'OwnerName' => (string) $attributes['OwnerName'],
                'IsOwner' => (string) $attributes['IsOwner'] == 'true',
                'IsPremium' => (string) $attributes['IsPremium'] == 'true',
                'CreatedDate' => $created_date,
                'ExpiredDate' => $expired_date,
                'Whoisguard' => [
                    'Enabled' => (string) $response->Whoisguard->attributes()['Enabled'] == 'True',
                    'ID' => (int) $response->Whoisguard->ID,
                    'ExpiredDate' => (int) $response->WhoisGuard->ExpiredDate,
                    'WhoisGuardEmail' => (string) $response->Whoisguard->EmailDetails->attributes('WhoisGuardEmail'),
                    'ForwardedTo' => (string) $response->Whoisguard->EmailDetails->attributes('ForwardedTo'),
                    'LastAutoEmailChangeDate' => (string) $response->Whoisguard->EmailDetails->attributes('LastAutoEmailChangeDate'),
                    'AutoEmailChangeFrequencyDays' => (string) $response->Whoisguard->EmailDetails->attributes('AutoEmailChangeFrequencyDays'),
                ],
            ];
        }

        return null;
    }
}
