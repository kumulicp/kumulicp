<?php

namespace App\Integrations\ServerManagers\HelmKubernetes\Services;

use App\AppInstance;
use App\Integrations\ServerManagers\HelmKubernetes\API\Ingress;
use App\Integrations\ServerManagers\HelmKubernetes\API\Middleware;
use App\Integrations\ServerManagers\Rancher\Charts\Ingress\RedirectChart as IngressRedirectChart;
use App\Integrations\ServerManagers\Rancher\Charts\Middleware\RedirectChart as MiddlewareRedirectChart;
use App\Organization;
use App\OrgServer;

class DomainMiddlewareService
{
    private Ingress $ingress;

    private Middleware $middleware;

    public function __construct(
        private Organization $organization,
        private OrgServer $server,
        private AppInstance $app_instance,
    ) {
        $this->middleware = new Middleware($organization, $server);
        $this->ingress = new Ingress($organization, $server);
    }

    public function addAppMiddleware()
    {
        $ingress_redirect_chart = new IngressRedirectChart($this->organization, $this->app_instance);
        $this->ingress->create($ingress_redirect_chart);

        $middleware_redirect_chart = new MiddlewareRedirectChart($this->organization, $this->app_instance);
        $this->middleware->create($middleware_redirect_chart);
    }

    public function update()
    {
        $this->removeAppMiddleware();

        if ($this->app_instance->domains()->count() > 1 && ! in_array($this->app_instance->status, ['deleting', 'deleted', 'deactivating', 'deactivated'])) {
            $this->addAppMiddleware();
        }
    }

    public function removeAppMiddleware()
    {
        $ingress_redirect_chart = new IngressRedirectChart($this->organization, $this->app_instance);
        if ($this->ingress->isActive($ingress_redirect_chart)) {
            $this->ingress->remove($ingress_redirect_chart);
        }

        $middleware_redirect_chart = new MiddlewareRedirectChart($this->organization, $this->app_instance);
        if ($this->middleware->isActive($middleware_redirect_chart)) {
            $this->middleware->remove($middleware_redirect_chart);
        }
    }
}
