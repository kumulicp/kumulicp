<?php

namespace App\Integrations\ServerManagers\Rancher\Support;

use App\Integrations\ServerManagers\Kubernetes\Support\K8sCredentialContext;
use Illuminate\Http\Client\PendingRequest;

/**
 * Reaches a cluster through Rancher: the server's address is Rancher's, and
 * the API key and secret are a Rancher API token pair sent as basic auth,
 * rather than a Kubernetes bearer token or client certificate.
 */
class RancherCredentialContext extends K8sCredentialContext
{
    protected function authenticate(PendingRequest $http): void
    {
        $http->withBasicAuth((string) $this->server->api_key, (string) $this->server->api_secret);
    }

    // Local and test Rancher instances use self-signed certificates, so
    // verification is only on outside those environments unless the server
    // says otherwise.
    public function tlsVerify(): bool
    {
        if ($this->server->setting('k8s_tls_verify') !== null) {
            return parent::tlsVerify();
        }

        return ! app()->environment('local', 'testing');
    }
}
