<?php

namespace App\Integrations\Registrars\Connect\Interfaces;

use App\OrgDomain;

class DomainsInterface
{
    public function select(OrgDomain $domain)
    {
        return new DomainInterface($domain);
    }

    public function list()
    {
        return OrgDomain::all()->map(function ($domain) {
            return [
                'id' => $domain->id,
                'name' => $domain->name,
                'user' => '',
                'created' => $domain->created_at,
                'expires' => '',
                'is_expired' => false,
                'is_locked' => false,
                'auto_renew' => false,
                'whois_guard' => false,
                'is_premium' => false,
                'is_our_dns' => false,
            ];
        });
    }
}
