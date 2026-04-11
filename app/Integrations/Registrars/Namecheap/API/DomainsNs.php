<?php

namespace App\Integrations\Registrars\Namecheap\API;

use App\Integrations\Registrars\Namecheap\Namecheap;

class DomainsNs extends Namecheap
{
    public function create($sld, $tld, $nameserver, $ip)
    {
        $this->command = 'namecheap.domains.ns.create';
        $this->parameters = [
            'SLD' => $sld,
            'TLD' => $tld,
            'Nameserver' => $nameserver,
            'IP' => $ip,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function delete($sld, $tld, $nameserver)
    {
        $this->command = 'namecheap.domains.dn.delete';
        $this->parameters = [
            'SLD' => $sld,
            'TLD' => $tld,
            'Nameserver' => $nameservers,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function info($sld, $tld, $nameserver)
    {
        $this->command = 'namecheap.domains.ns.info';
        $this->parameters = [
            'SLD' => $sld,
            'TLD' => $tld,
            'Nameserver' => $nameserver,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function update($sld, $tld, $nameserver, $old_ip, $ip)
    {
        $this->command = 'namecheap.domains.ns.update';
        $this->parameters = [
            'SLD' => $sld,
            'TLD' => $tld,
            'Nameserver' => $nameserver,
            'OldIP' => $old_ip,
            'IP' => $ip,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }
}
