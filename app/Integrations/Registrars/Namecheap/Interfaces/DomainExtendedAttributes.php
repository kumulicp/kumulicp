<?php

namespace App\Integrations\Registrars\Namecheap\Interfaces;

use App\OrgDomain;

class DomainExtendedAttributes
{
    private $avalable_tlds = ['ca'];

    private $tld;

    public function __construct(
        public OrgDomain $domain
    ) {
        $this->tld = $domain->tld->name;
    }

    public function get($options)
    {
        if ($this->available()) {
            $tld = $this->tld;

            return $this->$tld($options);
        }

        return null;
    }

    public function available()
    {
        return in_array($this->tld, $this->avalable_tlds);
    }

    public function ca($options)
    {
        $legal_type_allowed = ['CCO', 'CCT', 'RES', 'GOV', 'EDU', 'ASS', 'HOP', 'PRT', 'TDM', 'TRD', 'PLT', 'LAM', 'TRS', 'ABO', 'INB', 'LGR', 'OMK', 'MAJ'];
        $language_allowed = ['en', 'fr'];

        if (array_key_exists('cira_legal_type', $options)
            && array_key_exists('cira_language', $options)
            && in_array($options['cira_legal_type'], $legal_type_allowed)
            && in_array($options['cira_language'], $language_allowed)
        ) {
            $whois_allowed = ['CCT', 'RES', 'ABO', 'LGR'];
            $whois_display = in_array($options['cira_legal_type'], $whois_allowed) ? 'Private' : 'Full';

            return [
                'CIRALegalType' => $options['cira_legal_type'],
                'CIRAWhoisDisplay' => $whois_display,
                'CIRAAgreementVersion' => '2.0',
                'CIRAAgreementValue' => 'Y',
                'CIRALanguage' => $options['cira_language'],
            ];
        }

        return null;
    }
}
