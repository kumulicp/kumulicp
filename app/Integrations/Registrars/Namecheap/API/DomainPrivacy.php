<?php

namespace App\Integrations\Registrars\Namecheap\API;

use App\Integrations\Registrars\Namecheap\Namecheap;

class DomainPrivacy extends Namecheap
{
    public function changeEmailAddress($whoisguard_id)
    {
        $this->command = 'namecheap.whoisguard.changeemailaddress';
        $this->parameters = [
            'WhoisgaurdID' => $whoisguard_id,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function enable($whoisguard_id, $forward_to_email)
    {
        $this->command = 'namecheap.users.whoisguard.enable';
        $this->parameters = [
            'WhoisgaurdID' => $whoisguard_id,
            'ForwardToEmail' => $forward_to_email,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function disable($whoisguard_id)
    {
        $this->command = 'namecheap.whoisguard.disable';
        $this->parameters = [
            'WhoisgaurdID' => $whoisguard_id,
        ];

        $this->form()->post($this->basePath(), $this->postParameters());
    }

    public function list($list_type = 'ALL', $page = 1, $page_size = 20)
    {
        $this->command = 'namecheap.whoisguard.getList';
        $this->parameters = [
            'ListType' => $list_type,
            'Page' => $page,
            'PageSize' => $page_size,
        ];

        $this->send();
    }

    public function renew($whoisguard_id, $years)
    {
        $this->command = 'namecheap.whoisguard.renew';
        $this->parameters = [
            'WhoisgaurdID' => $whoisguard_id,
            'Years' => $years,
        ];

        $this->send();
    }
}
