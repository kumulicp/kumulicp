<?php

namespace App\Integrations\ServerManagers\HelmKubernetes;

use App\Integrations\ServerManagers\HelmKubernetes\API\HelmInstaller;
use App\Integrations\ServerManagers\HelmKubernetes\Support\HelmReleases;
use App\Integrations\ServerManagers\HelmKubernetes\Support\KubernetesApiClient;
use App\Organization;
use App\OrgServer;
use App\Server;

/**
 * Base class for the direct Kubernetes/Helm driver, sibling to
 * App\Integrations\ServerManagers\Rancher\Rancher. Holds the org/server
 * context and namespace convention (one namespace per organization, same as
 * Rancher) and exposes api()/helmReleases() helpers that talk to the
 * Kubernetes API directly, authenticating per-call from the Server's stored
 * k8s_* credential fields. No kubectl or helm binary is involved.
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
}
