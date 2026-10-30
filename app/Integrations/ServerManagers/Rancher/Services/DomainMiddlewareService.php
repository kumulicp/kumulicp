<?php

namespace App\Integrations\ServerManagers\Rancher\Services;

use App\Integrations\ServerManagers\Kubernetes\API\Ingress as BaseIngress;
use App\Integrations\ServerManagers\Kubernetes\API\Middleware as BaseMiddleware;
use App\Integrations\ServerManagers\Kubernetes\Services\DomainMiddlewareService as BaseDomainMiddlewareService;
use App\Integrations\ServerManagers\Rancher\API\Ingress;
use App\Integrations\ServerManagers\Rancher\API\Middleware;
use App\Integrations\ServerManagers\Rancher\Charts\Ingress\RedirectChart as IngressRedirectChart;
use App\Integrations\ServerManagers\Rancher\Charts\Middleware\MiddlewareChart;
use App\Integrations\ServerManagers\Rancher\Charts\Middleware\RedirectChart as MiddlewareRedirectChart;

class DomainMiddlewareService extends BaseDomainMiddlewareService
{
    protected function makeMiddleware(): BaseMiddleware
    {
        return new Middleware($this->organization, $this->server);
    }

    protected function makeIngress(): BaseIngress
    {
        return new Ingress($this->organization, $this->server);
    }

    public function updateChart(MiddlewareChart $chart)
    {
        if ($this->middleware->isActive($chart)) {
            if ($this->app_instance->status == 'deactivating') {
                $this->middleware->remove($chart);
            } else {
                $this->middleware->update($chart);
            }
        } elseif (! in_array($this->app_instance->status, ['deactivating', 'deactivated'])) {
            $this->middleware->create($chart);
        }
    }

    public function updateAppMiddleware()
    {
        if ($this->app_instance->domains->count() > 1) {
            $ingress_redirect_chart = new IngressRedirectChart($this->organization, $this->app_instance);
            $this->ingress->update($ingress_redirect_chart);

            $middleware_redirect_chart = new MiddlewareRedirectChart($this->organization, $this->app_instance);
            $this->middleware->update($middleware_redirect_chart);
        }
    }
}
