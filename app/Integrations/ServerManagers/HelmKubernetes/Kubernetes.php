<?php

namespace App\Integrations\ServerManagers\HelmKubernetes;

use App\Integrations\ServerManagers\HelmKubernetes\Support\HelmCli;
use App\Integrations\ServerManagers\HelmKubernetes\Support\KubectlCli;
use App\Organization;
use App\OrgServer;
use App\Server;

/**
 * Base class for the direct helm/kubectl driver, sibling to
 * App\Integrations\ServerManagers\Rancher\Rancher. Holds the org/server
 * context and namespace convention (one namespace per organization, same as
 * Rancher) and exposes helm()/kubectl() CLI helpers that authenticate
 * per-invocation from the Server's stored k8s_* credential fields.
 */
class Kubernetes
{
    public $name = 'Kubernetes';

    private ?string $namespace = null;

    public function __construct(
        public Organization $organization,
        public OrgServer $org_server,
    ) {}

    public function server(): Server
    {
        return $this->org_server->server;
    }

    public function setNamespace(?string $name = null)
    {
        $this->namespace = $name;
    }

    public function namespace()
    {
        if (! $this->namespace) {
            $this->namespace = $this->organization->slug;
        }

        return $this->namespace;
    }

    public function helm(): HelmCli
    {
        return new HelmCli($this->server());
    }

    public function kubectl(): KubectlCli
    {
        return new KubectlCli($this->server());
    }
}
