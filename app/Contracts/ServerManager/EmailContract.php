<?php

namespace App\Contracts\ServerManager;

use App\OrgDomain;

interface EmailContract
{
    public function emailList(OrgDomain $domain);

    public function existsEmail(OrgDomain $domain, string $address);

    public function email(OrgDomain $domain, string $username);

    public function addEmail(OrgDomain $domain, string $name, string $username, string $password, ?string $type = null);

    public function updateEmail(OrgDomain $domain, string $name, string $username, string $password, ?string $type = null);

    public function deleteEmail(OrgDomain $domain, string $username);

    public function listEmailForwarders(OrgDomain $domain);

    public function emailForwarders(string $email_address);

    public function deleteEmailForwarders(string $forwarder_address, string $destination_address);

    public function createDkimKey(OrgDomain $domain);

    public function dkimKey(OrgDomain $domain, string $job_id);
}
