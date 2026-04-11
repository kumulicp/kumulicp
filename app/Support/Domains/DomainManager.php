<?php

namespace App\Support\Domains;

use App\OrgDomain;
use App\Server;

class DomainManager
{
    public function __construct(public OrgDomain $domain) {}

    public function ip()
    {
        return gethostbyname($this->domain->name);
    }

    public function ipPointsToServer(Server $server)
    {
        if ($this->ip() == $server->ip) {
            return true;
        }

        return false;
    }

    public function get()
    {
        return $this->domain;
    }
}
