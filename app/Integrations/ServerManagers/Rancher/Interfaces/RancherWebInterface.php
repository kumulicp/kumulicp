<?php

namespace App\Integrations\ServerManagers\Rancher\Interfaces;

use App\Integrations\ServerManagers\Kubernetes\API\Application as BaseApplication;
use App\Integrations\ServerManagers\Kubernetes\API\Job as BaseJob;
use App\Integrations\ServerManagers\Kubernetes\API\KubernetesNamespace as BaseKubernetesNamespace;
use App\Integrations\ServerManagers\Kubernetes\API\Pod as BasePod;
use App\Integrations\ServerManagers\Kubernetes\API\Secret as BaseSecret;
use App\Integrations\ServerManagers\Kubernetes\Interfaces\KubernetesWebInterface;
use App\Integrations\ServerManagers\Kubernetes\Services\DomainMiddlewareService as BaseDomainMiddlewareService;
use App\Integrations\ServerManagers\Rancher\API\Application;
use App\Integrations\ServerManagers\Rancher\API\Job;
use App\Integrations\ServerManagers\Rancher\API\KubernetesNamespace;
use App\Integrations\ServerManagers\Rancher\API\Pod;
use App\Integrations\ServerManagers\Rancher\API\Secret;
use App\Integrations\ServerManagers\Rancher\Services\DomainMiddlewareService;

/**
 * The Kubernetes web interface, run against a cluster managed by Rancher.
 * Differs only in which API classes it uses, and in not recovering stuck
 * releases (Rancher manages its own).
 */
class RancherWebInterface extends KubernetesWebInterface
{
    protected function makeNamespace(): BaseKubernetesNamespace
    {
        return new KubernetesNamespace($this->organization, $this->server);
    }

    protected function makeApplication(): BaseApplication
    {
        return new Application($this->organization, $this->server);
    }

    protected function makeSecret(): BaseSecret
    {
        return new Secret($this->organization, $this->server);
    }

    protected function makeJob(): BaseJob
    {
        return new Job($this->organization, $this->server);
    }

    protected function makePod(): BasePod
    {
        return new Pod($this->organization, $this->server);
    }

    protected function makeDomainMiddleware(): BaseDomainMiddlewareService
    {
        return new DomainMiddlewareService($this->organization, $this->server, $this->app_instance);
    }

    // Rancher manages its own releases, so there is nothing of ours to recover.
    public function canRecoverStuckRelease(): bool
    {
        return false;
    }
}
