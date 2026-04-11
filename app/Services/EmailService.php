<?php

namespace App\Services;

use App\OrgDomain;
use App\Support\Facades\Settings;

class EmailService
{
    private $domain;

    // For @ MX record
    private bool $mx_1_record = false;

    private bool $spf_record = false;

    private bool $dkim_record = false;

    private bool $dmarc_record = false;

    public function checkEmailDNSSettings(OrgDomain $domain)
    {
        if (env('APP_ENV') != 'production') {
            return true;
        }

        $this->domain = $domain;
        $domain_name = $domain->name;

        $dkim = dns_get_record("mail._domainkey.$domain_name", DNS_TXT);
        if (count($dkim) == 1) {
            $this->dkim_record = $this->checkDKIM($dkim[0]);
        }

        $spf = dns_get_record($domain_name, DNS_TXT);
        if (count($spf) == 1) {
            $this->spf_record = $this->checkSPF($spf[0]);
        }

        $dmarc = dns_get_record("_dmarc.$domain_name", DNS_TXT);
        if (count($dmarc) == 1) {
            $this->dmarc_record = $this->checkDMARC($dmarc[0]);
        }

        $mx_1 = dns_get_record($domain_name, DNS_MX);
        if (count($mx_1) == 1) {
            $this->mx_1_record = $this->checkMX($mx_1[0]);
        }

        return $this->mx_1_record && $this->spf_record && $this->dkim_record && $this->dmarc_record;
    }

    private function checkSPF($record)
    {
        return $record['txt'] == $this->getRequiredSPF()['value'];
    }

    private function checkDMARC($record)
    {
        return $record['txt'] == $this->getRequiredDMARC()['value'];
    }

    private function checkDKIM($record)
    {
        return $record['txt'] == $this->getRequiredDKIM()['value'];
    }

    private function checkMX($record)
    {
        return $record['target'] == $this->domain->email_server?->server->address;
    }

    private function getRequiredSPF()
    {
        return [
            'type' => 'TXT',
            'host' => '@',
            'value' => "v=spf1 ip4:{$this->domain->email_server?->server->ip} ~all",
        ];
    }

    private function getRequiredDKIM()
    {
        return [
            'type' => 'TXT',
            'host' => 'mail._domainkey',
            'value' => $this->domain->dkim_public_key ?? __('messages.email.create_dkim_key'),
        ];
    }

    private function getRequiredDMARC()
    {
        $email = Settings::get('error_email');

        return [
            'type' => 'TXT',
            'host' => '_dmarc',
            'value' => 'v=DMARC1; p=reject; rua=mailto:'.$email.'; pct=100',
        ];
    }

    public function getRequiredDNSRecords(OrgDomain $domain)
    {
        $this->domain = $domain;
        $required_records = [];

        $required_records[] = [
            'type' => 'MX',
            'host' => '@',
            'value' => $this->domain->email_server?->server->address,
        ];
        $required_records[] = $this->getRequiredSPF();
        $required_records[] = $this->getRequiredDKIM();
        $required_records[] = $this->getRequiredDMARC();

        return $required_records;
    }
}
