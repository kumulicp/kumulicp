<?php

namespace App\Integrations\ServerManagers\Kubernetes;

use App\Integrations\ServerManagers\Kubernetes\API\HelmInstaller;
use App\Integrations\ServerManagers\Kubernetes\API\Secret;
use App\Integrations\ServerManagers\Kubernetes\Support\HelmReleases;
use App\Integrations\ServerManagers\Kubernetes\Support\KubernetesApiClient;
use App\Organization;
use App\OrgServer;
use App\Server;

/**
 * Base class for the direct Kubernetes driver, sibling to
 * App\Integrations\ServerManagers\Rancher\Rancher. Holds the org/server
 * context and namespace convention (one namespace per organization, same as
 * Rancher) and exposes api()/helmReleases() helpers that talk to the
 * Kubernetes API directly, authenticating per-call from the Server's stored
 * k8s_* credential fields. No command-line tooling is involved.
 */
class Kubernetes
{
    public $name = 'Kubernetes';

    private ?string $namespace = null;

    private ?KubernetesApiClient $api = null;

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

    public function api(): KubernetesApiClient
    {
        return $this->api ??= new KubernetesApiClient($this->server());
    }

    public function helmReleases(): HelmReleases
    {
        return new HelmReleases($this->api());
    }

    public function helmInstaller(): HelmInstaller
    {
        return new HelmInstaller($this->organization, $this->org_server);
    }

    public function secret(): Secret
    {
        return new Secret($this->organization, $this->org_server);
    }
}
