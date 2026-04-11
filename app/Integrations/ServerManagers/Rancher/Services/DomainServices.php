<?php

namespace App\Integrations\ServerManagers\Rancher\Services;

use App\OrgDomain;

trait DomainServices
{
    public function existsDomain(OrgDomain $domain)
    {
        $response = $this->middleware->isActive($domain->app_instance);

        return $response;
    }

    public function domain(OrgDomain $domain)
    {
        //
    }

    public function addDomain(OrgDomain $domain)
    {
        $domain_middleware = new DomainMiddlewareService($this->organization, $this->server, $domain->app_instance);
        $domain_middleware->update();
    }

    public function updateDomain(OrgDomain $domain)
    {
        $domain_middleware = new DomainMiddlewareService($this->organization, $this->server, $domain->app_instance);
        $domain_middleware->update();
    }

    public function deleteDomain(OrgDomain $domain)
    {
        $domain_middleware = new DomainMiddlewareService($this->organization, $this->server, $domain->app_instance);
        $domain_middleware->update();
    }

    public function hasDomainError() {}

    public function domainError() {}
}
