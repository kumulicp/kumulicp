<?php

namespace App\Integrations\ServerManagers\Rancher\Support;

use App\Integrations\ServerManagers\Kubernetes\Support\K8sCredentialContext;
use Illuminate\Http\Client\PendingRequest;

/**
 * Reaches a cluster's Kubernetes API through Rancher's cluster proxy
 * (<rancher>/k8s/clusters/<id>), authenticated with the Rancher API key, so
 * the cluster's own API server (port 6443) never has to be exposed.
 *
 * The cluster is 'local' unless the server's `rancher_cluster_id` setting
 * names another, or the address already points at a cluster's proxy path.
 */
class RancherClusterCredentials extends K8sCredentialContext
{
    protected function baseUrl(): string
    {
        $address = parent::baseUrl();

        if (str_contains($address, '/k8s/clusters/')) {
            return $address;
        }

        return $address.'/k8s/clusters/'.($this->server->setting('rancher_cluster_id') ?: 'local');
    }

    // A Rancher API token is accepted as a bearer token in "<key>:<secret>" form
    protected function authenticate(PendingRequest $http): void
    {
        $http->withToken($this->server->api_key.':'.$this->server->api_secret);
    }
}
