<?php

namespace App\Integrations\ServerManagers\Kubernetes;

use App\Integrations\ServerManagers\Kubernetes\API\HelmInstaller;
use App\Integrations\ServerManagers\Kubernetes\API\Secret;
use App\Integrations\ServerManagers\Kubernetes\Support\HelmReleases;
use App\Integrations\ServerManagers\Kubernetes\Support\K8sCredentialContext;
use App\Integrations\ServerManagers\Kubernetes\Support\KubernetesApiClient;
use App\Organization;
use App\OrgServer;
use App\Server;

/**
 * Base class for the Kubernetes driver and the foundation other drivers
 * (e.g. Rancher) build on. Holds the org/server context and namespace
 * convention (one namespace per organization) and exposes
 * api()/helmReleases() helpers that talk to the Kubernetes API directly,
 * authenticating per-call from the Server's stored k8s_* credential fields.
 * No command-line tooling is involved.
 *
 * A driver layered on top replaces only what differs from stock Kubernetes:
 *   - how requests are addressed and authenticated: credentials()
 *   - the URL layout / write verbs: makeApiClient() with a KubernetesApiClient subclass
 *   - reading status off an object: each API class's state() method
 *   - building a manifest: each API class's manifest()
 *   - app install and activation: the Application class's methods
 * Objects created from this one (secret(), helmInstaller()) share its API
 * client, so overriding makeApiClient() once switches them all.
 */
class Kubernetes
{
    public $name = 'Kubernetes';

    private ?string $namespace = null;

    private ?KubernetesApiClient $api = null;

    public function __construct(
        public Organization $organization,
        public OrgServer $org_server,
        ?KubernetesApiClient $api = null,
    ) {
        $this->api = $api;
    }

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
        return $this->api ??= $this->makeApiClient();
    }

    protected function makeApiClient(): KubernetesApiClient
    {
        return new KubernetesApiClient($this->server(), $this->credentials());
    }

    protected function credentials(): K8sCredentialContext
    {
        return new K8sCredentialContext($this->server());
    }

    public function helmReleases(): HelmReleases
    {
        return new HelmReleases($this->api());
    }

    public function helmInstaller(): HelmInstaller
    {
        return new HelmInstaller($this->organization, $this->org_server, $this->api());
    }

    public function secret(): Secret
    {
        return new Secret($this->organization, $this->org_server, $this->api());
    }
}
