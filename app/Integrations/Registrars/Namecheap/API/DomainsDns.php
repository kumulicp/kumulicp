<?php

namespace App\Integrations\Registrars\Namecheap\API;

use App\Integrations\Registrars\Namecheap\Helpers\DnsHosts;
use App\Integrations\Registrars\Namecheap\Namecheap;
use App\OrgDomain;
use App\Support\Facades\Subscription;

class DomainsDns extends Namecheap
{
    public function setDefault($sld, $tld)
    {
        $this->command = 'namecheap.domains.dns.setDefault';
        $this->parameters = [
            'SLD' => $sld,
            'TLD' => $tld,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function setCustom($sld, $tld, $name_server)
    {
        $this->command = 'namecheap.domains.dns.setCustom';
        $this->parameters = [
            'SLD' => $sld,
            'TLD' => $tld,
            'Nameserver' => $name_servers,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function list($sld, $tld)
    {
        $this->command = 'namecheap.domains.dns.getList';
        $this->parameters = [
            'SLD' => $sld,
            'TLD' => $tld,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function getHosts(OrgDomain $domain)
    {
        $sld = $domain->sld();
        $tld = $domain->tld->name;

        $this->command = 'namecheap.domains.dns.getHosts';
        $this->parameters = [
            'SLD' => $sld,
            'TLD' => $tld,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());

        if (! $this->hasError()) {
            $DomainDNSGetHostsResult = $this->response_content()->DomainDNSGetHostsResult;
            $attributes = $DomainDNSGetHostsResult->attributes();

            foreach ($DomainDNSGetHostsResult->host as $host) {
                $host_attributes = $host->attributes();
                $hosts[] = [
                    'HostID' => (int) $host_attributes['HostID'],
                    'Name' => (string) $host_attributes['Name'],
                    'Type' => (string) $host_attributes['Type'],
                    'Address' => (string) $host_attributes['Address'],
                    'MXPref' => (int) $host_attributes['MXPref'],
                    'TTL' => (int) $host_attributes['TTL'],
                ];
            }

            return [
                'Domain' => (string) $attributes['Domain'],
                'IsUsingOurDNS' => (string) $attributes['IsUsingOurDNS'] == 'true',
                'Hosts' => $hosts,
            ];
        }
    }

    // Wont use this function
    public function emailForwarding($domain_name)
    {
        $this->command = 'namecheap.domains.dns.emailForwarding';
        $this->parameters = [
            'DomainName' => $domain_name,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    // Won't use this function
    public function setEmailForwarding($domain_name)
    {
        $this->command = 'namecheap.domains.dns.setEmailForwarding';
        $this->parameters = [
            'DomainName' => $domain_name,
            // ... Mailbox1, ForwardTo1, Mailbox2, ForwardTo2...
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function setHosts(OrgDomain $domain)
    {
        $sld = $domain->sld();
        $tld = $domain->tld->name;

        $this->command = 'namecheap.domains.dns.setHosts';
        $this->request_type = 'POST';
        $parameters = [
            'SLD' => $sld,
            'TLD' => $tld,
        ];
        $records = new DnsHosts($domain);

        if (Subscription::emailEnabled() && $domain->organization->domains()->active()->emailEnabled()->primary()->count() > 0) {
            $records->addEmailRecords();
            $records->with_email = true;
        }

        $this->parameters = array_merge($parameters, $records->convertToNamecheap());

        $this->form()->post($this->basePath(), $this->postParameters());

        if (! $this->hasError()) {

            $attributes = $this->response_content()->DomainDNSSetHostsResult->attributes();

            return [
                'Domain' => $attributes['Domain'],
                'IsSuccess' => (string) $attributes['IsSuccess'] == 'true',
            ];
        }

        return null;
    }
}
