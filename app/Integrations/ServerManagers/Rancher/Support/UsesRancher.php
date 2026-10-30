<?php

namespace App\Integrations\ServerManagers\Rancher\Support;

use App\Integrations\ServerManagers\Kubernetes\API\Secret as KubernetesSecret;
use App\Integrations\ServerManagers\Kubernetes\Support\K8sCredentialContext;
use App\Integrations\ServerManagers\Kubernetes\Support\KubernetesApiClient;
use App\Integrations\ServerManagers\Rancher\API\Secret;

/**
 * Mixed into a Rancher API class (which extends its Kubernetes counterpart)
 * to point it at Rancher: the Rancher client and credentials, Rancher's way
 * of reporting an object's state, and a way to reach the cluster's own
 * Kubernetes API through Rancher's proxy for what Rancher doesn't mediate.
 */
trait UsesRancher
{
    private ?KubernetesApiClient $cluster_api = null;

    protected function makeApiClient(): KubernetesApiClient
    {
        return new RancherApiClient($this->server(), $this->credentials());
    }

    protected function credentials(): K8sCredentialContext
    {
        return new RancherCredentialContext($this->server());
    }

    /**
     * The cluster's Kubernetes API through Rancher's cluster proxy
     * (see RancherClusterCredentials), with standard paths and objects.
     * Chart Secrets and Job lookups go here rather than through Rancher's API.
     */
    public function clusterApi(): KubernetesApiClient
    {
        return $this->cluster_api ??= new KubernetesApiClient($this->server(), new RancherClusterCredentials($this->server()));
    }

    // Secrets are applied through the cluster proxy, so they need Rancher's Secret, not the Kubernetes one.
    public function secret(): KubernetesSecret
    {
        return new Secret($this->organization, $this->org_server);
    }

    protected function rancher(): RancherApiClient
    {
        return $this->api();
    }

    /**
     * Reads active(1), non existant (0) or transitioning (2) off the
     * `metadata.state` Rancher adds to every object. $active is the state
     * name that counts as active for the kind (e.g. 'bound' for a claim).
     */
    protected function rancherState(array $object, string $active = 'active'): int
    {
        if (($object['metadata']['state']['transitioning'] ?? false) === true) {
            return 2;
        }

        return ($object['metadata']['state']['name'] ?? null) === $active ? 1 : 0;
    }
}
