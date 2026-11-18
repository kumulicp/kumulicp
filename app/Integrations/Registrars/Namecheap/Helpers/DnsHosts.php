<?php

namespace App\Integrations\Registrars\Namecheap\Helpers;

use App\OrgDomain;
use App\Support\Facades\Email;
use Illuminate\Support\Arr;

class DnsHosts
{
    private $dns_records = [];

    private $domain;

    public $with_email;

    // The constructor adds the email records, and DomainsDns::setHosts() asks for them again.
    private bool $email_records_added = false;

    public function __construct(OrgDomain $domain)
    {
        $this->domain = $domain;
        $app_instance = $domain->app_instance()->with('web_server.server')->first();
        $subdomains = $domain->subdomains()->with('app_instance.web_server.server')->get();

        $this->dns_records = $subdomains->filter(function ($subdomain) {
            return ! empty($subdomain->value);
        })->map(function ($subdomain) {
            $ip = $subdomain->app_instance?->web_server?->server?->ip;

            return [
                'HostName' => $subdomain->host,
                'RecordType' => $subdomain->app_instance ? 'A' : $subdomain->type,
                'Address' => $subdomain->app_instance ? $ip : $subdomain->value,
                'TL' => (string) $subdomain->ttl,
            ];
        });
        $this->addEmailRecords();
    }

    public function hasRecords(): bool
    {
        return count($this->dns_records) > 0;
    }

    /**
     * Safe to call more than once: the first call turns $dns_records from a collection into an array, so a
     * second one used to fail on ->all(), and would have listed every email record twice.
     */
    public function addEmailRecords()
    {
        if ($this->email_records_added) {
            return $this;
        }

        $domain_email = $this->domain->email_server()->with('server')->first();
        if ($domain_email) {
            $this->email_records_added = true;
            $this->with_email = true;
            $email_server = $domain_email->server;
            $records = [];

            foreach (Email::requiredDNSRecords($this->domain) as $record) {
                $records[] = [
                    'HostName' => Arr::get($record, 'host'),
                    'RecordType' => Arr::get($record, 'type'),
                    'Address' => Arr::get($record, 'value'),
                    'TL' => 'auto',
                ];
            }

            $this->dns_records = array_merge($records, $this->dns_records->all());
        }

        return $this;
    }

    public function convertToNamecheap()
    {
        $array = [];

        if ($this->with_email) {
            $array['EmailType'] = 'MX';
        }

        $n = 1;
        foreach ($this->dns_records as $record) {
            foreach ($record as $key => $value) {
                $array[$key.$n] = $value;
            }

            $n++;
        }

        return $array;
    }
}
