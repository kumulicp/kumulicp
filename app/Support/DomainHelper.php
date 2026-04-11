<?php

namespace App\Support;

use App\OrgDomain;

class DomainHelper
{
    public static function validate($domain)
    {
        return preg_match('/^(?!\-)(?:(?:[a-zA-Z\d][a-zA-Z\d\-]{0,61})?[a-zA-Z\d]\.){1,126}(?!\d+)[a-zA-Z\d]{1,63}$/', $domain);
    }

    public static function confirmSpf(OrgDomain $domain)
    {
        $domain = $domain->name;
        $records = dns_get_record($domain, DNS_TXT);

        foreach ($records as $record) {
            // Check if record contains v=spf and ip4 is correct ip
        }
    }

    public static function confirmDkimKey(OrgDomain $domain)
    {
        $domain = 'default._domainkey.'.$domain->name;
        $records = dns_get_record($domain, DNS_TXT);

        foreach ($records as $record) {
            // Check if record contains v=DKIM1 and p=[dkimkey]
        }
    }

    public static function confirmDmarc(OrgDomain $domain)
    {
        $domain = 'default._domainkey.'.$domain->name;
        $records = dns_get_record($domain, DNS_TXT);

        foreach ($records as $record) {
            // Check if record contains v=DMARC1
        }
    }
}
